<?php
// /modules/junior_church/parent_portal.php
session_start();

// Check if parent is already authenticated via main portal or parent gateway
$isAuthenticated = isset($_SESSION['user_id']) || isset($_SESSION['parent_portal_auth_id']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Junior Church Parent Portal | Household of David</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Montserrat:wght@600;800;900&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <!-- CropperJS for profile picture updates -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Montserrat', 'sans-serif'] },
                    colors: { hodBlue: '#0A0E17', hodRed: '#D11920' },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.6' } }
                    }
                }
            }
        }
    </script>

    <style>
        body { color: #1F2937; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.08); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #D11920; }

        .glass-panel {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.04);
        }

        /* Child selector pill active state */
        .child-pill.active {
            background: #0A0E17;
            color: #fff;
            box-shadow: 0 4px 16px rgba(10, 14, 23, 0.25);
        }
        .child-pill.active img,
        .child-pill.active .pill-avatar {
            border-color: rgba(255,255,255,0.4);
        }

        /* Timeline connector line */
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 19px;
            top: 48px;
            bottom: -16px;
            width: 2px;
            background: #E5E7EB;
        }
        .timeline-item:last-child::before {
            display: none;
        }

        /* Attendance badge animations */
        @keyframes checkPop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        .badge-pop { animation: checkPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }

        /* Theatre mode for projecting videos/PDFs */
        .theatre-overlay {
            position: fixed;
            inset: 0;
            background: #000;
            z-index: 99999;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .theatre-overlay.active { display: flex; }

        /* Modal scroll constraint */
        .modal-body-scroll {
            max-height: 80vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Stat card hover */
        .stat-card { transition: transform 0.2s, box-shadow 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px -6px rgba(0,0,0,0.1); }

        /* Resource button hover */
        .res-btn { transition: all 0.15s; }
        .res-btn:hover { transform: translateY(-1px); }
    </style>
</head>
<body class="bg-gray-50 antialiased min-h-screen relative overflow-x-hidden selection:bg-hodRed selection:text-white">

    <!-- ===================== AMBIENT BACKGROUND ===================== -->
    <div class="fixed inset-0 z-[-1] overflow-hidden bg-white">
        <div class="absolute inset-0 bg-gradient-to-br from-blue-50/40 via-white to-red-50/30"></div>
        <div class="absolute top-0 right-1/4 w-[500px] h-[500px] bg-hodRed/5 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-0 left-1/4 w-[400px] h-[400px] bg-hodBlue/5 rounded-full blur-[150px]"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-amber-100/20 rounded-full blur-[180px]"></div>
    </div>


    <!-- ===================== VIEW 1: SECURITY GATE (Login) ===================== -->
    <div id="gateView" class="<?php echo $isAuthenticated ? 'hidden' : ''; ?> relative z-10 flex flex-col items-center justify-center min-h-screen p-4 md:p-6">

        <div class="w-full max-w-md mx-auto space-y-8 animate-fade-in-up">

            <!-- Logo & Welcome -->
            <div class="text-center space-y-4">
                <div class="w-20 h-20 bg-hodBlue rounded-3xl flex items-center justify-center mx-auto shadow-xl shadow-hodBlue/20 mb-2">
                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <h1 class="text-3xl md:text-4xl font-heading font-black text-gray-900 tracking-tight">Parent Portal</h1>
                <p class="text-gray-500 text-sm md:text-base font-medium max-w-sm mx-auto leading-relaxed">See what your child has been learning, their attendance, and take-home resources from Junior Church.</p>
            </div>

            <!-- Login Card -->
            <div class="glass-panel rounded-[2rem] p-6 md:p-8 shadow-xl">
                <form id="gateForm" class="space-y-5">
                    <input type="hidden" name="action" value="authenticate_parent">

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Your Phone Number *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            </div>
                            <input type="tel" name="phone" required placeholder="Enter your registered phone"
                                   class="w-full pl-12 pr-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodBlue focus:ring-2 focus:ring-hodBlue/15 transition-all">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Child's Date of Birth *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                            <input type="date" name="child_dob" required
                                   class="w-full pl-12 pr-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodBlue focus:ring-2 focus:ring-hodBlue/15 transition-all">
                        </div>
                        <p class="text-[11px] text-gray-400 font-medium mt-2 pl-1">For security, enter any one of your children's date of birth.</p>
                    </div>

                    <button type="submit" id="btnGateSubmit"
                            class="w-full bg-hodBlue hover:bg-gray-900 text-white py-4 rounded-xl font-black uppercase tracking-widest text-sm transition-all shadow-lg shadow-hodBlue/20 flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        Verify &amp; Enter
                    </button>
                </form>

                <div class="mt-6 pt-5 border-t border-gray-100 text-center">
                    <p class="text-xs text-gray-400 font-medium">Already have a church account?</p>
                    <a href="/auth/login.php" class="text-xs font-bold text-hodRed hover:text-hodBlue transition-colors mt-1 inline-block">Log in to the full portal instead</a>
                </div>
            </div>

            <!-- Back link -->
            <div class="text-center">
                <a href="/connect.php" class="text-sm font-bold text-gray-400 hover:text-hodBlue transition-colors flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Back to Connect Page
                </a>
            </div>
        </div>
    </div>


    <!-- ===================== VIEW 2: PARENT DASHBOARD ===================== -->
    <div id="dashboardView" class="<?php echo $isAuthenticated ? '' : 'hidden'; ?> relative z-10 min-h-screen">

        <!-- Top Nav Bar -->
        <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-xl border-b border-gray-100 shadow-sm">
            <div class="max-w-5xl mx-auto px-4 md:px-6 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-hodBlue rounded-xl flex items-center justify-center text-white shadow-md">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <h1 class="text-sm font-heading font-black text-gray-900 tracking-tight leading-none">Junior Church</h1>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Parent Portal</p>
                    </div>
                </div>
                <button onclick="logoutPortal()" class="text-xs font-bold text-gray-500 hover:text-hodRed flex items-center gap-1.5 bg-gray-50 hover:bg-red-50 px-3 py-2 rounded-lg border border-gray-200 hover:border-red-200 transition-all">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    Sign Out
                </button>
            </div>
        </nav>

        <!-- Dashboard Content -->
        <div class="max-w-5xl mx-auto px-4 md:px-6 py-6 md:py-8 space-y-6">

            <!-- Welcome Banner -->
            <div id="welcomeBanner" class="bg-gradient-to-br from-hodBlue to-gray-900 text-white p-6 md:p-8 rounded-3xl shadow-xl relative overflow-hidden animate-fade-in-up">
                <div class="absolute top-0 right-0 w-48 h-48 bg-white/5 rounded-full blur-2xl -mr-12 -mt-12"></div>
                <div class="absolute bottom-0 left-0 w-32 h-32 bg-hodRed/10 rounded-full blur-2xl -ml-8 -mb-8"></div>
                <div class="relative z-10">
                    <p class="text-white/60 text-xs font-bold uppercase tracking-widest mb-1">Welcome back</p>
                    <h2 class="text-2xl md:text-3xl font-heading font-black tracking-tight" id="dashWelcomeName">Parent</h2>
                    <p class="text-white/70 text-sm font-medium mt-2 max-w-lg">Here's what your little ones have been up to in Junior Church. Browse their attendance, lessons, and take-home resources below.</p>
                </div>
            </div>

            <!-- Child Selector Pills -->
            <div id="childSelectorWrap" class="animate-fade-in-up" style="animation-delay: 0.1s;">
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3">Your Children</p>
                <div id="childSelector" class="flex gap-3 overflow-x-auto pb-2 custom-scrollbar -mx-1 px-1">
                    <!-- Populated by JS -->
                </div>
            </div>

            <!-- Stats Row -->
            <div id="statsRow" class="grid grid-cols-3 gap-3 md:gap-4 animate-fade-in-up" style="animation-delay: 0.15s;">
                <div class="stat-card bg-white p-4 md:p-5 rounded-2xl border border-gray-100 shadow-sm text-center">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Services</p>
                    <p class="text-2xl md:text-3xl font-black text-gray-900" id="statTotal">0</p>
                </div>
                <div class="stat-card bg-white p-4 md:p-5 rounded-2xl border border-gray-100 shadow-sm text-center">
                    <p class="text-[10px] font-bold text-green-500 uppercase tracking-widest mb-1">Present</p>
                    <p class="text-2xl md:text-3xl font-black text-green-600" id="statPresent">0</p>
                </div>
                <div class="stat-card bg-white p-4 md:p-5 rounded-2xl border border-gray-100 shadow-sm text-center">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Absent</p>
                    <p class="text-2xl md:text-3xl font-black text-gray-400" id="statAbsent">0</p>
                </div>
            </div>

            <!-- Timeline Section -->
            <div id="timelineSection" class="animate-fade-in-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-heading font-black text-gray-900 tracking-tight">Recent Sundays</h3>
                    <button onclick="openUpdatePicModal()" class="text-xs font-bold text-hodBlue hover:text-hodRed flex items-center gap-1.5 bg-blue-50 hover:bg-red-50 px-3 py-2 rounded-lg border border-blue-100 hover:border-red-200 transition-all">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path></svg>
                        Update Photo
                    </button>
                </div>
                <div id="timelineList" class="space-y-4">
                    <!-- Populated by JS -->
                </div>
            </div>

            <!-- Empty state -->
            <div id="emptyState" class="hidden text-center py-16">
                <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-5 border-2 border-dashed border-gray-200">
                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <p class="text-gray-500 font-bold text-lg">No children found</p>
                <p class="text-gray-400 text-sm mt-1">We couldn't find any children linked to your profile. Please contact the Junior Church team.</p>
            </div>

            <!-- Footer -->
            <div class="text-center pt-8 pb-4">
                <p class="text-[11px] text-gray-400 font-medium">Household of David &middot; Junior Church Parent Portal</p>
                <a href="/connect.php" class="text-[11px] font-bold text-hodRed hover:text-hodBlue mt-1 inline-block transition-colors">Back to Connect</a>
            </div>
        </div>
    </div>


    <!-- ===================== MODAL: UPDATE CHILD PHOTO ===================== -->
    <div id="updatePicModal" class="fixed inset-0 bg-gray-900/70 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overflow-y-auto">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 my-auto flex flex-col" style="max-height: 85vh;">
            <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center rounded-t-3xl shrink-0">
                <h3 class="text-lg font-bold text-gray-900">Update Photo</h3>
                <button onclick="closeModal('updatePicModal')" class="text-gray-400 hover:text-gray-900 bg-gray-50 p-1.5 rounded-full">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-6 space-y-5 modal-body-scroll">
                <!-- Current photo preview -->
                <div class="flex justify-center">
                    <div class="relative group w-28 h-28 rounded-full bg-gray-50 border-4 border-gray-100 overflow-hidden shadow-inner flex items-center justify-center">
                        <img id="updatePicPreview" src="" class="w-full h-full object-cover hidden">
                        <div id="updatePicPlaceholder" class="text-center text-gray-400">
                            <svg class="w-8 h-8 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path></svg>
                        </div>
                        <label for="parentUploadInput" class="absolute inset-0 bg-black/50 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer text-white">
                            <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            <span class="text-[10px] font-bold uppercase">Choose</span>
                        </label>
                        <input type="file" id="parentUploadInput" accept="image/*" class="hidden">
                    </div>
                </div>
                <p class="text-xs text-gray-400 text-center font-medium">Tap the photo above to select a new image</p>
                <button onclick="submitNewPhoto()" id="btnSavePhoto" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-3.5 rounded-xl font-bold shadow-md transition-all hidden">
                    Save New Photo
                </button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: IMAGE CROPPER ===================== -->
    <div id="cropModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-gray-900 rounded-3xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300 shadow-2xl flex flex-col border border-gray-700" style="max-height: 85vh;">
            <div class="p-6 flex-1 flex items-center justify-center bg-black min-h-[280px]">
                <div class="w-full max-h-[380px]"><img id="imageToCrop" class="max-w-full block hidden"></div>
            </div>
            <div class="p-4 bg-gray-800 border-t border-gray-700 flex justify-end gap-3 shrink-0">
                <button onclick="closeModal('cropModal')" class="px-5 py-2 rounded-xl font-bold text-gray-300 hover:text-white transition-colors">Cancel</button>
                <button id="btnApplyCrop" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2 rounded-xl font-bold shadow-lg transition-all">Apply Crop</button>
            </div>
        </div>
    </div>

    <!-- ===================== THEATRE MODE (Video / PDF Projection) ===================== -->
    <div id="theatreOverlay" class="theatre-overlay">
        <button class="absolute top-4 right-5 z-[100000] bg-white/15 backdrop-blur-sm border border-white/25 text-white w-11 h-11 rounded-full flex items-center justify-center cursor-pointer hover:bg-white/30 hover:scale-110 transition-all" onclick="closeTheatre()">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
        <div id="theatreContent" class="w-full h-full flex items-center justify-center"></div>
    </div>

    <!-- ===================== GLOBAL ACTION BLOCKER ===================== -->
    <div id="globalActionBlocker" class="fixed inset-0 z-[9999] hidden" style="background: rgba(255,255,255,0.5); backdrop-filter: blur(2px);">
        <div class="w-full h-full flex items-center justify-center">
            <div class="bg-white px-8 py-5 rounded-2xl shadow-2xl border border-gray-100 flex items-center gap-4">
                <svg class="animate-spin h-5 w-5 text-hodBlue" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="text-sm font-bold text-gray-700">Processing...</span>
            </div>
        </div>
    </div>


    <!-- ===================== JAVASCRIPT ===================== -->
    <script>
        const API_URL = '/api/parent_portal_api.php';
        let portalData = {};
        let selectedChildId = null;
        let cropper = null;
        let newPhotoBase64 = '';

        // ==========================================
        // CENTRALIZED TOAST
        // ==========================================
        function showToast(msg, type = 'success') {
            const colors = {
                success: 'linear-gradient(135deg, #10B981, #059669)',
                error:   'linear-gradient(135deg, #EF4444, #DC2626)',
                warning: 'linear-gradient(135deg, #F59E0B, #D97706)',
                info:    'linear-gradient(135deg, #3B82F6, #2563EB)'
            };
            Toastify({
                text: msg, gravity: "top", position: "center", duration: 3500,
                close: true, stopOnFocus: true,
                style: {
                    background: colors[type] || colors.success,
                    borderRadius: "14px", fontWeight: "700", fontSize: "0.85rem",
                    padding: "14px 28px", boxShadow: "0 12px 32px -8px rgba(0,0,0,0.25)",
                    fontFamily: "inherit", maxWidth: "90vw"
                }
            }).showToast();
        }

        // ==========================================
        // ACTION BLOCKER
        // ==========================================
        function lockScreenAction() { document.getElementById('globalActionBlocker').classList.remove('hidden'); }
        function unlockScreenAction() { document.getElementById('globalActionBlocker').classList.add('hidden'); }

        // ==========================================
        // HARD-LOCK MODAL SYSTEM
        // ==========================================
        function openModal(id) {
            const m = document.getElementById(id);
            if(!m) return;
            if(m.parentElement !== document.body) document.body.appendChild(m);
            m.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            m.scrollTop = 0;
            requestAnimationFrame(() => {
                m.classList.remove('opacity-0');
                const panel = m.children[0];
                if(panel) panel.classList.remove('scale-95');
            });
        }
        function closeModal(id) {
            const m = document.getElementById(id);
            if(!m) return;
            m.classList.add('opacity-0');
            const panel = m.children[0];
            if(panel) panel.classList.add('scale-95');
            setTimeout(() => {
                m.classList.add('hidden');
                const openModals = document.querySelectorAll('.fixed.inset-0:not(.hidden)');
                let realModals = 0;
                openModals.forEach(el => { if(el.id !== 'globalActionBlocker') realModals++; });
                if(realModals === 0) document.body.style.overflow = '';
                if(id === 'cropModal' && cropper) { cropper.destroy(); cropper = null; }
            }, 300);
        }
        // Hard-lock: backdrop click does nothing
        $(document).on('click', '.fixed.inset-0', function(e) {
            if(e.target === this) { e.stopPropagation(); e.preventDefault(); return false; }
        });

        // ==========================================
        // THEATRE MODE
        // ==========================================
        function extractYouTubeId(url) {
            if(!url) return null;
            const match = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
            return match ? match[1] : null;
        }
        function openTheatre(type, url) {
            const container = $('#theatreContent');
            container.empty();
            if(type === 'youtube') {
                const vid = extractYouTubeId(url);
                if(!vid) { showToast('Invalid video link', 'error'); return; }
                container.html(`<iframe src="https://www.youtube.com/embed/${vid}?autoplay=1&rel=0&modestbranding=1" style="width:90vw;height:80vh;border-radius:16px;" allow="autoplay;encrypted-media;fullscreen" allowfullscreen></iframe>`);
            } else if(type === 'pdf') {
                container.html(`<iframe src="${url}" style="width:95vw;height:95vh;background:#fff;border-radius:8px;"></iframe>`);
            } else if(type === 'image') {
                container.html(`<img src="${url}" style="max-width:95vw;max-height:95vh;object-fit:contain;border-radius:8px;">`);
            } else {
                container.html(`<iframe src="${url}" style="width:95vw;height:95vh;background:#fff;border-radius:8px;"></iframe>`);
            }
            $('#theatreOverlay').addClass('active');
            document.body.style.overflow = 'hidden';
            const overlay = document.getElementById('theatreOverlay');
            if(overlay.requestFullscreen) overlay.requestFullscreen();
            else if(overlay.webkitRequestFullscreen) overlay.webkitRequestFullscreen();
        }
        function closeTheatre() {
            $('#theatreOverlay').removeClass('active');
            $('#theatreContent').empty();
            document.body.style.overflow = '';
            if(document.fullscreenElement) document.exitFullscreen();
        }
        $(document).on('keydown', function(e) {
            if(e.key === 'Escape' && $('#theatreOverlay').hasClass('active')) closeTheatre();
        });

        // ==========================================
        // SECURITY GATE FORM
        // ==========================================
        $('#gateForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#btnGateSubmit');
            const orig = btn.html();
            const spinner = `<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

            btn.prop('disabled', true).html(spinner + 'Verifying...');
            lockScreenAction();

            $.ajax({
                url: API_URL, type: 'POST',
                data: new FormData(this), processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).html(orig);
                    unlockScreenAction();
                    if(res.status === 'success') {
                        showToast(res.message, 'success');
                        setTimeout(() => {
                            $('#gateView').addClass('hidden');
                            $('#dashboardView').removeClass('hidden');
                            loadParentDashboard();
                        }, 600);
                    } else {
                        showToast(res.message, 'error');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(orig);
                    unlockScreenAction();
                    showToast('Connection failed. Please try again.', 'error');
                }
            });
        });

        // ==========================================
        // LOAD PARENT DASHBOARD
        // ==========================================
        function loadParentDashboard() {
            $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
                if(res.status === 'auth_required') {
                    $('#dashboardView').addClass('hidden');
                    $('#gateView').removeClass('hidden');
                    return;
                }
                if(res.status === 'success') {
                    portalData = res;

                    if(!res.children || res.children.length === 0) {
                        $('#childSelectorWrap, #statsRow, #timelineSection').addClass('hidden');
                        $('#emptyState').removeClass('hidden');
                        return;
                    }

                    // Build child selector pills
                    let pillsHtml = '';
                    res.children.forEach((c, idx) => {
                        const pic = c.picture_path
                            ? `<img src="${c.picture_path}" class="w-10 h-10 rounded-full object-cover border-2 border-gray-200">`
                            : `<div class="pill-avatar w-10 h-10 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center font-black text-blue-500 text-sm border-2 border-gray-200">${c.child_first_name.charAt(0)}</div>`;

                        const age = c.dob ? Math.floor((new Date() - new Date(c.dob)) / 31557600000) : null;
                        const ageStr = age !== null ? `${age} yrs` : '';

                        pillsHtml += `
                        <button onclick="selectChild(${c.id})" data-child-id="${c.id}"
                                class="child-pill ${idx === 0 ? 'active' : ''} flex items-center gap-3 px-4 py-3 rounded-2xl border border-gray-200 bg-white shadow-sm shrink-0 transition-all hover:shadow-md cursor-pointer">
                            ${pic}
                            <div class="text-left">
                                <p class="text-sm font-bold leading-none">${c.child_first_name} ${c.child_last_name}</p>
                                ${ageStr ? `<p class="text-[10px] font-bold text-gray-400 mt-0.5 uppercase tracking-wider">${ageStr}</p>` : ''}
                            </div>
                        </button>`;
                    });
                    $('#childSelector').html(pillsHtml);

                    // Select first child
                    selectChild(res.children[0].id);
                }
            });
        }

        // ==========================================
        // SELECT CHILD & RENDER TIMELINE
        // ==========================================
        function selectChild(childId) {
            selectedChildId = childId;

            // Update pill active states
            $('.child-pill').removeClass('active');
            $(`.child-pill[data-child-id="${childId}"]`).addClass('active');

            const child = portalData.children.find(c => c.id == childId);
            const timeline = portalData.timeline[childId] || [];

            // Update welcome name
            if(child) {
                $('#dashWelcomeName').text(child.child_first_name + "'s Dashboard");
            }

            // Calculate stats
            const total = timeline.length;
            const present = timeline.filter(t => t.status === 'Present').length;
            const absent = total - present;
            $('#statTotal').text(total);
            $('#statPresent').text(present);
            $('#statAbsent').text(absent);

            // Render timeline
            if(timeline.length === 0) {
                $('#timelineList').html(`
                    <div class="bg-white rounded-2xl border border-gray-100 p-8 text-center">
                        <p class="text-gray-400 font-bold">No service records yet</p>
                        <p class="text-gray-400 text-xs mt-1">Attendance data will appear here after your child's first Sunday.</p>
                    </div>`);
                return;
            }

            let html = '';
            timeline.forEach((t, idx) => {
                const dateObj = new Date(t.service_date);
                const dayName = dateObj.toLocaleDateString('en-US', { weekday: 'short' });
                const dayNum = dateObj.getDate();
                const monthName = dateObj.toLocaleDateString('en-US', { month: 'short' });

                const isPresent = t.status === 'Present';
                const statusBadge = isPresent
                    ? `<span class="badge-pop inline-flex items-center gap-1 bg-green-50 text-green-700 px-2.5 py-1 rounded-lg text-[10px] font-bold border border-green-200">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        Present</span>`
                    : `<span class="inline-flex items-center gap-1 bg-gray-100 text-gray-500 px-2.5 py-1 rounded-lg text-[10px] font-bold border border-gray-200">Absent</span>`;

                // Resources
                let resourceBtns = '';
                if(t.service_file_path) {
                    const fType = getFileType(t.service_file_path);
                    const canProject = (fType === 'pdf' || fType === 'image');
                    resourceBtns += `<a href="${t.service_file_path}" download class="res-btn inline-flex items-center gap-1.5 bg-purple-50 hover:bg-purple-100 text-purple-700 px-3 py-2 rounded-lg text-[10px] font-bold border border-purple-200">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        Activity Sheet</a> `;
                    if(canProject) {
                        resourceBtns += `<button onclick="openTheatre('${fType}', '${t.service_file_path}')" class="res-btn inline-flex items-center gap-1.5 bg-gray-900 hover:bg-gray-800 text-white px-3 py-2 rounded-lg text-[10px] font-bold shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            View</button> `;
                    }
                }
                if(t.media_link) {
                    const ytId = extractYouTubeId(t.media_link);
                    if(ytId) {
                        resourceBtns += `<button onclick="openTheatre('youtube', '${t.media_link}')" class="res-btn inline-flex items-center gap-1.5 bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-[10px] font-bold shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            Watch Video</button>`;
                    } else {
                        resourceBtns += `<a href="${t.media_link}" target="_blank" class="res-btn inline-flex items-center gap-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-2 rounded-lg text-[10px] font-bold border border-blue-200">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                            Open Link</a>`;
                    }
                }

                // YouTube thumbnail embed in card
                let ytThumb = '';
                if(t.media_link) {
                    const ytId = extractYouTubeId(t.media_link);
                    if(ytId) {
                        ytThumb = `
                        <div class="mt-3 rounded-xl overflow-hidden bg-black cursor-pointer group relative" onclick="openTheatre('youtube', '${t.media_link}')">
                            <img src="https://img.youtube.com/vi/${ytId}/mqdefault.jpg" class="w-full h-32 md:h-40 object-cover opacity-80 group-hover:opacity-60 transition-opacity">
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="w-12 h-12 bg-red-600 rounded-full flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                                    <svg class="w-6 h-6 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </div>
                            </div>
                        </div>`;
                    }
                }

                // Pages covered
                const pagesLine = t.master_pages_covered
                    ? `<div class="flex items-center gap-1.5 text-amber-700 mt-2">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        <span class="text-[11px] font-bold">Curriculum: ${t.master_pages_covered}</span>
                    </div>`
                    : '';

                html += `
                <div class="timeline-item relative pl-14 pb-2" style="animation: fadeInUp 0.4s ${idx * 0.06}s both;">
                    <!-- Date bubble -->
                    <div class="absolute left-0 top-0 w-10 h-10 rounded-xl ${isPresent ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'} flex flex-col items-center justify-center shadow-sm">
                        <span class="text-[9px] font-bold uppercase leading-none">${dayName}</span>
                        <span class="text-sm font-black leading-none">${dayNum}</span>
                    </div>

                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-4 md:p-5">
                        <div class="flex items-start justify-between gap-3 mb-1">
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm md:text-base font-bold text-gray-900 leading-snug">${t.topic}</h4>
                                <p class="text-[11px] font-medium text-gray-400 mt-0.5">${monthName} ${dayNum}, ${dateObj.getFullYear()}</p>
                            </div>
                            ${statusBadge}
                        </div>

                        ${pagesLine}
                        ${ytThumb}

                        ${resourceBtns ? `<div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-gray-50">${resourceBtns}</div>` : ''}
                    </div>
                </div>`;
            });

            $('#timelineList').html(html);
        }

        function getFileType(path) {
            if(!path) return 'unknown';
            const ext = path.split('.').pop().toLowerCase();
            if(['pdf'].includes(ext)) return 'pdf';
            if(['png','jpg','jpeg','gif','webp'].includes(ext)) return 'image';
            return 'file';
        }

        // ==========================================
        // UPDATE CHILD PHOTO
        // ==========================================
        function openUpdatePicModal() {
            if(!selectedChildId) { showToast('Select a child first', 'warning'); return; }
            newPhotoBase64 = '';
            $('#btnSavePhoto').addClass('hidden');

            const child = portalData.children.find(c => c.id == selectedChildId);
            if(child && child.picture_path) {
                $('#updatePicPreview').attr('src', child.picture_path).removeClass('hidden');
                $('#updatePicPlaceholder').addClass('hidden');
            } else {
                $('#updatePicPreview').addClass('hidden');
                $('#updatePicPlaceholder').removeClass('hidden');
            }
            openModal('updatePicModal');
        }

        $('#parentUploadInput').on('change', function(e) {
            const file = e.target.files[0];
            if(!file) return;
            const reader = new FileReader();
            reader.onload = function(event) {
                const img = document.getElementById('imageToCrop');
                img.src = event.target.result;
                img.classList.remove('hidden');
                openModal('cropModal');
                if(cropper) cropper.destroy();
                cropper = new Cropper(img, {
                    aspectRatio: 1, viewMode: 1, dragMode: 'move',
                    autoCropArea: 0.8, guides: false, center: true,
                    highlight: false, toggleDragModeOnDblclick: false
                });
            };
            reader.readAsDataURL(file);
            $(this).val('');
        });

        $('#btnApplyCrop').on('click', function() {
            if(!cropper) return;
            const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
            newPhotoBase64 = canvas.toDataURL('image/png');
            $('#updatePicPreview').attr('src', newPhotoBase64).removeClass('hidden');
            $('#updatePicPlaceholder').addClass('hidden');
            $('#btnSavePhoto').removeClass('hidden');
            closeModal('cropModal');
        });

        function submitNewPhoto() {
            if(!newPhotoBase64 || !selectedChildId) return;

            const btn = $('#btnSavePhoto');
            const orig = btn.html();
            btn.prop('disabled', true).html('Saving...');
            lockScreenAction();

            let formData = new FormData();
            formData.append('action', 'update_child_pic');
            formData.append('child_id', selectedChildId);
            formData.append('image_base64', newPhotoBase64);

            $.ajax({
                url: API_URL, type: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).html(orig);
                    unlockScreenAction();
                    showToast(res.message, res.status);
                    if(res.status === 'success') {
                        closeModal('updatePicModal');
                        loadParentDashboard(); // Refresh to show new photo
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(orig);
                    unlockScreenAction();
                    showToast('Upload failed. Please try again.', 'error');
                }
            });
        }

        // ==========================================
        // LOGOUT
        // ==========================================
        function logoutPortal() {
            $.post(API_URL, { action: 'logout' }, function() {
                showToast('Signed out securely', 'info');
                setTimeout(() => {
                    $('#dashboardView').addClass('hidden');
                    $('#gateView').removeClass('hidden');
                    portalData = {};
                    selectedChildId = null;
                }, 500);
            }, 'json');
        }

        // ==========================================
        // INIT
        // ==========================================
        $(document).ready(function() {
            <?php if($isAuthenticated): ?>
                loadParentDashboard();
            <?php endif; ?>
        });
    </script>
</body>
</html>