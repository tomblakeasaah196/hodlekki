<?php
// /modules/junior_church/index.php
require_once '../../includes/header.php';
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>
<!-- ===================== EXTERNAL LIBRARIES ===================== -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<!-- Select2 for Searchable Parent Dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- ===================== SELECT2 CUSTOM STYLES ===================== -->
<style>
    /* Select2 Theme Override to match the app's design system */
    .select2-container--default .select2-selection--single {
        height: 48px !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 0.75rem !important;
        padding: 8px 12px !important;
        font-weight: 700 !important;
        font-size: 0.875rem !important;
        color: #111827 !important;
        background: #fff !important;
        display: flex !important;
        align-items: center !important;
    }
    .select2-container--default .select2-selection--single:focus,
    .select2-container--default.select2-container--open .select2-selection--single {
        border-color: #1e3a5f !important;
        box-shadow: 0 0 0 2px rgba(30,58,95,0.1) !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 48px !important;
        right: 10px !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__placeholder {
        color: #9ca3af !important;
        font-weight: 500 !important;
    }
    .select2-dropdown {
        border: 1px solid #e5e7eb !important;
        border-radius: 0.75rem !important;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15) !important;
        overflow: hidden !important;
        z-index: 9999 !important;
    }
    .select2-results__option {
        padding: 10px 14px !important;
        font-weight: 600 !important;
        font-size: 0.85rem !important;
    }
    .select2-results__option--highlighted[aria-selected] {
        background-color: #1e3a5f !important;
    }
    .select2-search--dropdown .select2-search__field {
        border: 1px solid #e5e7eb !important;
        border-radius: 0.5rem !important;
        padding: 10px 14px !important;
        font-size: 0.875rem !important;
        outline: none !important;
    }
    .select2-search--dropdown .select2-search__field:focus {
        border-color: #1e3a5f !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__clear {
        font-size: 1.2rem !important;
        margin-right: 8px !important;
        color: #9ca3af !important;
    }
    /* Modal max-height constraint for mobile */
    .modal-body-scroll {
        max-height: 80vh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    /* Fullscreen Theatre Mode */
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
    .theatre-overlay.active {
        display: flex;
    }
    .theatre-overlay iframe,
    .theatre-overlay embed,
    .theatre-overlay img {
        max-width: 100%;
        max-height: calc(100vh - 60px);
        border: none;
    }
    .theatre-close-btn {
        position: absolute;
        top: 16px;
        right: 20px;
        z-index: 100000;
        background: rgba(255,255,255,0.15);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255,255,255,0.25);
        color: #fff;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    .theatre-close-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.1);
    }
    /* Resource Card hover effects */
    .resource-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .resource-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.12);
    }
    /* Medical pulse animation */
    @keyframes medPulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    .med-pulse { animation: medPulse 1.5s ease-in-out infinite; }

    /* Quick-dial hover tooltip */
    .quick-dial-btn {
        transition: all 0.15s;
    }
    .quick-dial-btn:hover {
        transform: scale(1.15);
    }
</style>

<!-- ===================== MAIN CONTENT ===================== -->
<div class="max-w-7xl mx-auto space-y-6 pb-10">

    <!-- ==================== HEADER BANNER ==================== -->
    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Junior Church</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Manage the children's roster, curriculums, and Sunday attendance.</p>
            </div>
        </div>
        <div class="relative z-10 flex gap-3 w-full md:w-auto flex-wrap">
            <button onclick="openCurriculumUploadModal()" class="flex-1 md:flex-none bg-white border border-gray-200 text-gray-700 hover:text-purple-700 hover:border-purple-400 hover:bg-purple-50 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                Upload Curriculum
            </button>
            <button onclick="openServiceModal()" class="flex-1 md:flex-none bg-white border border-gray-200 text-gray-700 hover:text-hodBlue hover:border-hodBlue hover:bg-blue-50 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                Log Service
            </button>
            <button onclick="openChildModal()" class="flex-1 md:flex-none bg-hodBlue hover:bg-blue-900 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-lg shadow-blue-900/20 flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Register Child
            </button>
        </div>
    </div>

    <!-- ==================== TAB NAVIGATION (3 TABS) ==================== -->
    <div class="flex bg-gray-100 p-1.5 rounded-2xl w-full md:max-w-lg animate-fade-in-up" style="animation-delay: 0.1s;">
        <button onclick="switchTab('roster')" id="tabBtn-roster" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-hodBlue shadow-sm">Children Roster</button>
        <button onclick="switchTab('services')" id="tabBtn-services" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Service Tracker</button>
        <button onclick="switchTab('curriculum')" id="tabBtn-curriculum" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Curriculums</button>
    </div>

    <!-- ==================== TAB 1: CHILDREN ROSTER ==================== -->
    <div id="view-roster" class="animate-fade-in-up" style="animation-delay: 0.2s;">
        <div id="rosterGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div class="col-span-full py-20 text-center"><svg class="animate-spin h-8 w-8 text-hodBlue mx-auto" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></div>
        </div>
    </div>

    <!-- ==================== TAB 2: SERVICE TRACKER ==================== -->
    <div id="view-services" class="hidden animate-fade-in-up" style="animation-delay: 0.2s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Service Date</th>
                            <th class="px-6 py-4">Curriculum Topic</th>
                            <th class="px-6 py-4">Teacher in Charge</th>
                            <th class="px-6 py-4 text-center">Kids Present</th>
                            <th class="px-6 py-4 text-center">Resources</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="servicesList" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==================== TAB 3: CURRICULUMS & RESOURCES ==================== -->
    <div id="view-curriculum" class="hidden animate-fade-in-up" style="animation-delay: 0.2s;">
        <div id="curriculumGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="col-span-full py-16 text-center text-gray-400 font-medium">Loading curriculum library...</div>
        </div>
    </div>

    <!-- ==================== ATTENDANCE VIEW ==================== -->
    <div id="view-attendance" class="hidden animate-fade-in-up">
        <div class="mb-4">
            <button onclick="switchTab('services')" class="text-sm font-bold text-gray-500 hover:text-hodBlue flex items-center gap-2 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg> Back to Services
            </button>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <!-- Attendance Header -->
            <div class="p-6 md:p-8 border-b border-gray-100 bg-blue-50/30 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h3 class="text-2xl font-display font-bold text-gray-900" id="attSvcTopic">Loading Topic...</h3>
                    <p class="text-sm font-bold text-hodBlue mt-1" id="attSvcDate">Loading Date...</p>
                    <p class="text-xs text-gray-500 mt-1" id="attSvcPages"></p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="markAllPresent()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-md shadow-green-500/20 transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Mark All Present
                    </button>
                    <div class="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm">
                        <span class="text-xs font-bold text-gray-500 uppercase tracking-widest block mb-0.5">Total Present</span>
                        <span class="text-xl font-black text-green-600" id="attTotalPresent">0</span>
                    </div>
                </div>
            </div>

            <!-- Service Resources Bar (YouTube / Files) -->
            <div id="attResourcesBar" class="hidden border-b border-gray-100 bg-gray-50/50 px-6 py-4">
                <div class="flex flex-wrap gap-3" id="attResourcesBtns"></div>
            </div>

            <!-- Attendance Grid -->
            <div class="p-6 md:p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="attendanceGrid"></div>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MODAL: REGISTER / EDIT CHILD ===================== -->
<div id="childModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl transform scale-95 transition-transform duration-300 my-auto flex flex-col" style="max-height: 85vh;">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-white/90 backdrop-blur z-10 rounded-t-3xl shrink-0">
            <h3 id="childModalTitle" class="text-xl font-bold text-gray-900">Register Child</h3>
            <button onclick="closeModal('childModal')" class="text-gray-400 hover:text-gray-900 bg-gray-50 p-1.5 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>

        <form id="childForm" class="p-6 space-y-6 modal-body-scroll">
            <input type="hidden" name="action" value="save_child">
            <input type="hidden" name="child_id" id="inpChildId">
            <input type="hidden" name="existing_picture" id="inpExistingPic">

            <!-- Photo Upload -->
            <div class="flex justify-center mb-6">
                <div class="relative group w-32 h-32 rounded-full bg-gray-50 border-4 border-gray-100 overflow-hidden shadow-inner flex items-center justify-center">
                    <img id="childDisplayPic" src="" class="w-full h-full object-cover hidden">
                    <div id="childPicPlaceholder" class="text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L28 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <span class="text-[10px] font-bold uppercase tracking-wider">Upload</span>
                    </div>
                    <label for="childUploadInput" class="absolute inset-0 bg-black/50 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer text-white">
                        <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path></svg>
                        <span class="text-[10px] font-bold uppercase">Change</span>
                        <input type="file" id="childUploadInput" accept="image/*" class="hidden">
                    </label>
                </div>
            </div>

            <!-- Child Details Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">First Name *</label>
                    <input type="text" name="child_first_name" id="inpFname" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold text-gray-900">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Last Name *</label>
                    <input type="text" name="child_last_name" id="inpLname" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold text-gray-900">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Date of Birth</label>
                    <input type="date" name="dob" id="inpDob" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold text-gray-900 text-sm">
                </div>
                <div><!-- empty cell for alignment --></div>

                <!-- Parent / Guardian Section with Select2 Search -->
                <div class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-5 bg-blue-50/30 p-4 rounded-xl border border-blue-100/50">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Primary Guardian *</label>
                        <select name="parent_id" id="inpParent" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold text-gray-900 bg-white select2-parents" style="width: 100%"></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Secondary Guardian (Optional)</label>
                        <select name="parent2_id" id="inpParent2" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold text-gray-900 bg-white select2-parents" style="width: 100%"></select>
                    </div>
                </div>

                <!-- Medical Notes -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-red-500 uppercase mb-2 flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        Medical Notes / Allergies
                    </label>
                    <textarea name="medical_notes" id="inpMed" rows="2" placeholder="e.g., Peanut allergy, Asthma..." class="w-full px-4 py-3 border border-red-200 bg-red-50/30 rounded-xl focus:border-red-400 outline-none font-medium text-red-900 placeholder-red-300 resize-none"></textarea>
                </div>
            </div>

            <button type="submit" id="btnSaveChild" class="w-full bg-hodBlue hover:bg-blue-900 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all mt-2">Save Child Record</button>
        </form>
    </div>
</div>

<!-- ===================== MODAL: LOG SUNDAY SERVICE ===================== -->
<div id="serviceModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg transform scale-95 transition-transform duration-300 my-auto flex flex-col" style="max-height: 85vh;">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Log Sunday Service</h3>
            <button onclick="closeModal('serviceModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="serviceForm" class="p-6 space-y-5 modal-body-scroll" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_service">

            <!-- Section 1: Core Details -->
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Core Details</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Service Date *</label>
                    <input type="date" name="service_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-hodBlue outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Teacher in Charge *</label>
                    <select name="teacher_id" id="inpTeacher" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-hodBlue outline-none bg-white"></select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Curriculum Topic *</label>
                <input type="text" name="topic" required placeholder="e.g., The Story of David" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-hodBlue outline-none">
            </div>

            <!-- Section 2: Resources & Tracking -->
            <div class="border-t border-gray-100 pt-5 mt-2 space-y-4">
                <p class="text-[10px] font-bold text-purple-500 uppercase tracking-widest">Resources & Tracking</p>

                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Master Curriculum Pages Covered</label>
                    <input type="text" name="master_pages_covered" placeholder="e.g., Pages 14-16, Chapter 3" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-hodBlue outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Weekly File Upload (PDF, Image, Worksheet)</label>
                    <input type="file" name="service_file" id="inpServiceFile" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.gif" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium text-gray-700 bg-white file:mr-3 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Media Link (YouTube, Spotify, etc.)</label>
                    <input type="url" name="media_link" placeholder="https://www.youtube.com/watch?v=..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-hodBlue outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Internal Notes</label>
                    <textarea name="notes" rows="2" placeholder="Any pastoral observations, prayer requests..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-hodBlue outline-none resize-none"></textarea>
                </div>
            </div>

            <button type="submit" class="w-full bg-hodBlue text-white px-6 py-3.5 rounded-xl font-bold shadow-md hover:bg-blue-900 transition-all">Initialize Tracker</button>
        </form>
    </div>
</div>

<!-- ===================== MODAL: UPLOAD MASTER CURRICULUM ===================== -->
<div id="curriculumUploadModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 my-auto flex flex-col" style="max-height: 85vh;">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-purple-50/50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                Upload Master Curriculum
            </h3>
            <button onclick="closeModal('curriculumUploadModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="curriculumUploadForm" class="p-6 space-y-5 modal-body-scroll" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_curriculum">

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Curriculum Title *</label>
                <input type="text" name="title" required placeholder="e.g., Q2 2026 Teachers Manual" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-purple-500 outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Description</label>
                <textarea name="description" rows="2" placeholder="Brief description of this curriculum..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-purple-500 outline-none resize-none"></textarea>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Upload File (PDF, DOCX, Images) *</label>
                <input type="file" name="curriculum_file" required accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.gif,.pptx" class="w-full px-4 py-3 border border-dashed border-purple-300 rounded-xl font-medium text-gray-700 bg-purple-50/30 file:mr-3 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200">
            </div>

            <button type="submit" id="btnUploadCurriculum" class="w-full bg-purple-600 text-white px-6 py-3.5 rounded-xl font-bold shadow-md hover:bg-purple-700 transition-all">Upload to Library</button>
        </form>
    </div>
</div>

<!-- ===================== MODAL: VIEW SERVICE DETAILS ===================== -->
<div id="serviceDetailModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 my-auto flex flex-col" style="max-height: 85vh;">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-blue-50/50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900" id="svcDetailTitle">Service Details</h3>
            <button onclick="closeModal('serviceDetailModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-4 modal-body-scroll" id="svcDetailBody"></div>
    </div>
</div>

<!-- ===================== MODAL: IMAGE CROPPER ===================== -->
<div id="cropModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-gray-900 rounded-3xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300 shadow-2xl flex flex-col border border-gray-700" style="max-height: 85vh;">
        <div class="p-6 flex-1 flex items-center justify-center bg-black min-h-[300px]"><div class="w-full max-h-[400px]"><img id="imageToCrop" class="max-w-full block hidden"></div></div>
        <div class="p-4 bg-gray-800 border-t border-gray-700 flex justify-end gap-3 shrink-0">
            <button onclick="closeModal('cropModal')" class="px-5 py-2 rounded-xl font-bold text-gray-300 hover:text-white transition-colors">Cancel</button>
            <button id="btnApplyCrop" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2 rounded-xl font-bold shadow-lg transition-all">Apply Crop</button>
        </div>
    </div>
</div>

<!-- ===================== THEATRE MODE CONTAINER ===================== -->
<div id="theatreOverlay" class="theatre-overlay">
    <button class="theatre-close-btn" onclick="closeTheatre()">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
    </button>
    <div id="theatreContent" class="w-full h-full flex items-center justify-center"></div>
</div>

<!-- ===================== GLOBAL ACTION BLOCKER (Double-Click Prevention) ===================== -->
<div id="globalActionBlocker" class="fixed inset-0 z-[9999] hidden" style="background: rgba(255,255,255,0.45); backdrop-filter: blur(2px);">
    <div class="w-full h-full flex items-center justify-center">
        <div class="bg-white px-8 py-5 rounded-2xl shadow-2xl border border-gray-100 flex items-center gap-4">
            <svg class="animate-spin h-6 w-6 text-hodBlue" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span class="text-sm font-bold text-gray-700">Processing, please wait...</span>
        </div>
    </div>
</div>


<!-- ===================== MAIN JAVASCRIPT ===================== -->
<script>
    const API_URL = '/api/junior_church_api.php';
    let globalData = {};
    let currentChildBase64 = '';
    let cropper = null;
    let currentAttendanceServiceId = null;

    // ==========================================
    // PILLAR 4: CENTRALIZED TOAST NOTIFICATIONS
    // ==========================================
    // One single "Master Wrapper" for all notifications site-wide.
    // Every AJAX callback calls this. Consistent look, consistent timing.
    function showToast(msg, type = 'success') {
        const colors = {
            success: 'linear-gradient(135deg, #10B981, #059669)',
            error:   'linear-gradient(135deg, #EF4444, #DC2626)',
            warning: 'linear-gradient(135deg, #F59E0B, #D97706)',
            info:    'linear-gradient(135deg, #3B82F6, #2563EB)'
        };
        Toastify({
            text: msg,
            gravity: "top",
            position: "center",
            duration: 3500,
            close: true,
            stopOnFocus: true,
            style: {
                background: colors[type] || colors.success,
                borderRadius: "14px",
                fontWeight: "700",
                fontSize: "0.875rem",
                padding: "14px 28px",
                boxShadow: "0 12px 32px -8px rgba(0,0,0,0.25)",
                fontFamily: "inherit",
                maxWidth: "90vw"
            }
        }).showToast();
    }

    // ==========================================
    // PILLAR 3: GLOBAL ACTION BLOCKER
    // ==========================================
    // Drops a transparent overlay the instant a save/submit fires.
    // Physically blocks all mouse interaction until server responds.
    function lockScreenAction() {
        document.getElementById('globalActionBlocker').classList.remove('hidden');
    }
    function unlockScreenAction() {
        document.getElementById('globalActionBlocker').classList.add('hidden');
    }

    // ==========================================
    // UI CORE LOGIC
    // ==========================================
    function switchTab(tabId) {
        $('#view-roster, #view-services, #view-curriculum, #view-attendance').addClass('hidden').removeClass('animate-fade-in-up');
        $('#tabBtn-roster, #tabBtn-services, #tabBtn-curriculum').removeClass('bg-white text-hodBlue shadow-sm').addClass('text-gray-500 hover:text-gray-900');

        if(tabId) {
            $(`#view-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
            $(`#tabBtn-${tabId}`).removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-hodBlue shadow-sm');
        }
    }

    // ==========================================
    // PILLAR 1 & 2: HARD-LOCK MODAL SYSTEM
    // ==========================================
    // - openModal: rips modal to <body>, locks scroll, centers on screen
    // - closeModal: only triggered by explicit X/Cancel/Save buttons
    // - Backdrop click does NOTHING (no onclick on overlay)
    // - Modal inner panel uses stopPropagation as safety net
    function openModal(id) {
        const m = document.getElementById(id);
        if(!m) return;

        // PILLAR 2: Rip modal to <body> so it's never trapped in a scrolling container
        if(m.parentElement !== document.body) {
            document.body.appendChild(m);
        }

        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        // Force the overlay scroll to top so modal is always visible
        m.scrollTop = 0;

        requestAnimationFrame(() => {
            m.classList.remove('opacity-0');
            // Animate the first child (the white panel) into view
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

            // Only unlock body scroll if no other modals are still open
            const openModals = document.querySelectorAll('.fixed.inset-0:not(.hidden)');
            // Filter out the global action blocker from the count
            let realModals = 0;
            openModals.forEach(el => { if(el.id !== 'globalActionBlocker') realModals++; });
            if(realModals === 0) {
                document.body.style.overflow = '';
            }

            // Module-specific cleanup
            if(id === 'cropModal' && cropper) {
                cropper.destroy();
                cropper = null;
            }
            if(id === 'childModal') {
                const form = m.querySelector('form');
                if(form) form.reset();
                $('#childDisplayPic').addClass('hidden');
                $('#childPicPlaceholder').removeClass('hidden');
                currentChildBase64 = '';
                if($.fn.select2 && $('#inpParent').hasClass('select2-hidden-accessible')) {
                    $('#inpParent, #inpParent2').val('').trigger('change');
                }
            }
            if(id === 'serviceModal') {
                const form = m.querySelector('form');
                if(form) form.reset();
            }
            if(id === 'curriculumUploadModal') {
                const form = m.querySelector('form');
                if(form) form.reset();
            }
        }, 300);
    }

    // PILLAR 1: Hard-lock backdrop — clicking the dark overlay does nothing.
    // Only the inner white panel is interactive. Attach once on DOMReady.
    $(document).ready(function() {
        // For every modal overlay: stop clicks on the inner panel from bubbling,
        // and ignore clicks on the dark backdrop entirely.
        $(document).on('click', '.fixed.inset-0', function(e) {
            // If click is directly on the overlay (not on a child element), do nothing.
            // This prevents accidental closes when clicking the dark area.
            if(e.target === this) {
                e.stopPropagation();
                e.preventDefault();
                return false;
            }
        });
    });

    // ==========================================
    // FORM SUBMISSION ENGINE (Unified)
    // ==========================================
    function handleAjaxForm(formId, successCallback) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();

            let btn;
            if(formId === 'childForm') btn = $('#btnSaveChild');
            else if(formId === 'curriculumUploadForm') btn = $('#btnUploadCurriculum');
            else btn = $(this).find('button[type="submit"]');

            const origHtml = btn.html();
            const spinner = `<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

            // PILLAR 3: Disable button + drop the global action blocker
            btn.prop('disabled', true).html(spinner + 'Processing...');
            lockScreenAction();

            // ALL forms use FormData for safe handling of files and large payloads
            let formData = new FormData(this);

            // Append child image base64 if present
            if(formId === 'childForm') {
                formData.append('image_base64', currentChildBase64);
            }

            $.ajax({
                url: API_URL,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).html(origHtml);
                    unlockScreenAction();
                    showToast(res.message, res.status);
                    if(res.status === 'success' && successCallback) successCallback(res);
                },
                error: function(xhr) {
                    btn.prop('disabled', false).html(origHtml);
                    unlockScreenAction();
                    showToast("Server Error. Please try again.", "error");
                    console.error('Form submit error:', xhr.responseText);
                }
            });
        });
    }

    // ==========================================
    // THEATRE MODE (Fullscreen Projection)
    // ==========================================
    function openTheatre(type, url) {
        const container = $('#theatreContent');
        container.empty();

        if(type === 'youtube') {
            const videoId = extractYouTubeId(url);
            if(!videoId) { showToast('Invalid YouTube URL', 'error'); return; }
            container.html(`<iframe src="https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&modestbranding=1"
                style="width: 90vw; height: 80vh; border-radius: 16px;"
                allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>`);
        } else if(type === 'pdf') {
            container.html(`<iframe src="${url}" style="width: 95vw; height: 95vh; background: #fff; border-radius: 8px;"></iframe>`);
        } else if(type === 'image') {
            container.html(`<img src="${url}" style="max-width: 95vw; max-height: 95vh; object-fit: contain; border-radius: 8px;">`);
        } else {
            container.html(`<iframe src="${url}" style="width: 95vw; height: 95vh; background: #fff; border-radius: 8px;"></iframe>`);
        }

        $('#theatreOverlay').addClass('active');
        document.body.style.overflow = 'hidden';

        // Try native fullscreen
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

    // ESC key to close theatre
    $(document).on('keydown', function(e) {
        if(e.key === 'Escape') {
            if($('#theatreOverlay').hasClass('active')) closeTheatre();
        }
    });

    function extractYouTubeId(url) {
        if(!url) return null;
        const match = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        return match ? match[1] : null;
    }

    function getFileType(path) {
        if(!path) return 'unknown';
        const ext = path.split('.').pop().toLowerCase();
        if(['pdf'].includes(ext)) return 'pdf';
        if(['png','jpg','jpeg','gif','webp'].includes(ext)) return 'image';
        if(['doc','docx'].includes(ext)) return 'doc';
        if(['pptx','ppt'].includes(ext)) return 'ppt';
        return 'file';
    }

    function getFileIcon(type) {
        const icons = {
            pdf: '<svg class="w-8 h-8 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zM6 20V4h7v5h5v11H6z"/><path d="M8 12h1.5c.83 0 1.5.67 1.5 1.5S10.33 15 9.5 15H9v2H8v-5zm1 2h.5c.28 0 .5-.22.5-.5s-.22-.5-.5-.5H9v1zm3-2h1.5c.83 0 1.5.67 1.5 1.5v1c0 .83-.67 1.5-1.5 1.5H12v-4zm1 3h.5c.28 0 .5-.22.5-.5v-1c0-.28-.22-.5-.5-.5H13v2zm3-3h2v1h-1v.5h1v1h-1V17h-1v-5z"/></svg>',
            image: '<svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>',
            doc: '<svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
            ppt: '<svg class="w-8 h-8 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>',
            file: '<svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>'
        };
        return icons[type] || icons.file;
    }

    // ==========================================
    // DATA RENDERING - MAIN DASHBOARD
    // ==========================================
    function loadDashboard() {
        $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                globalData = res;
                renderRoster(res);
                renderServices(res);
                renderCurriculums(res);
                populateDropdowns(res);
            }
        });
    }

    // ---------- ROSTER ----------
    function renderRoster(res) {
        let rHtml = '';
        if(res.children.length === 0) {
            rHtml = `<div class="col-span-full py-16 text-center">
                <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <p class="text-gray-500 font-bold text-lg">No children registered yet</p>
                <p class="text-gray-400 text-sm mt-1">Click "Register Child" to add the first one.</p>
            </div>`;
        } else {
            res.children.forEach(c => {
                const pic = c.picture_path
                    ? `<img src="${c.picture_path}" class="w-full h-full object-cover" alt="${c.child_first_name}">`
                    : `<div class="w-full h-full bg-gradient-to-br from-blue-50 to-blue-100 text-blue-400 flex items-center justify-center font-black text-3xl">${c.child_first_name.charAt(0)}</div>`;

                const medAlert = c.medical_notes
                    ? `<div class="absolute top-3 right-3 bg-red-500 text-white p-1.5 rounded-full shadow-md med-pulse" title="Medical Alert: ${c.medical_notes}"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div>`
                    : '';

                const age = c.dob ? Math.floor((new Date() - new Date(c.dob)) / 31557600000) + ' yrs' : 'Age Unknown';

                // Parent contact buttons
                const parentPhone = c.parent_phone || '';
                const contactBtns = parentPhone ? `
                    <div class="flex gap-1.5 mt-2">
                        <a href="tel:${parentPhone}" class="quick-dial-btn flex-1 flex items-center justify-center gap-1 bg-blue-50 hover:bg-blue-100 text-blue-600 py-1.5 rounded-lg text-[10px] font-bold transition-all" title="Call ${c.parent_fname}">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            Call
                        </a>
                        <a href="https://wa.me/${parentPhone.replace(/[^0-9]/g, '')}" target="_blank" class="quick-dial-btn flex-1 flex items-center justify-center gap-1 bg-green-50 hover:bg-green-100 text-green-600 py-1.5 rounded-lg text-[10px] font-bold transition-all" title="WhatsApp ${c.parent_fname}">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.489a.75.75 0 00.918.918l4.455-1.495A11.94 11.94 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.359 0-4.542-.747-6.332-2.017l-.442-.318-3.082 1.034 1.034-3.082-.318-.442A9.956 9.956 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
                            WhatsApp
                        </a>
                    </div>` : '';

                // Parent 2 display
                const parent2Line = (c.parent2_fname)
                    ? `<p class="text-sm font-bold text-gray-800 truncate mt-0.5" title="${c.parent2_phone || ''}">&amp; ${c.parent2_fname} ${c.parent2_lname}</p>`
                    : '';

                rHtml += `
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow overflow-hidden relative">
                    <div class="h-32 w-full bg-gray-100 relative overflow-hidden">${pic}${medAlert}</div>
                    <div class="p-5">
                        <h3 class="text-lg font-bold text-gray-900 truncate">${c.child_first_name} ${c.child_last_name}</h3>
                        <p class="text-xs font-bold text-hodBlue uppercase tracking-wider mb-3">${age}</p>
                        ${c.medical_notes ? `<div class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-3"><p class="text-[10px] font-bold text-red-600 uppercase tracking-wider">Medical Alert</p><p class="text-xs font-medium text-red-800 mt-0.5">${c.medical_notes}</p></div>` : ''}
                        <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Parent / Guardian</p>
                            <p class="text-sm font-bold text-gray-800 truncate" title="${parentPhone}">${c.parent_fname} ${c.parent_lname}</p>
                            ${parent2Line}
                            <p class="text-xs font-medium text-gray-500">${parentPhone || 'No phone'}</p>
                            ${contactBtns}
                        </div>
                        <button onclick="openChildModal(${c.id})" class="mt-4 w-full text-xs font-bold text-gray-600 border border-gray-200 hover:border-hodBlue hover:text-hodBlue py-2 rounded-xl transition-colors">Edit Profile</button>
                    </div>
                </div>`;
            });
        }
        $('#rosterGrid').html(rHtml);
    }

    // ---------- SERVICES ----------
    function renderServices(res) {
        let sHtml = '';
        if(res.services.length === 0) {
            sHtml = '<tr><td colspan="6" class="px-6 py-12 text-center text-gray-500 font-medium">No services logged yet. Click "Log Service" to get started.</td></tr>';
        } else {
            res.services.forEach(s => {
                const dateNice = new Date(s.service_date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });

                // Resource indicators
                let resourceIcons = '';
                if(s.service_file_path) {
                    resourceIcons += `<span class="inline-flex items-center gap-1 bg-purple-50 text-purple-600 px-2 py-1 rounded-lg text-[10px] font-bold border border-purple-200" title="Has file attachment"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>File</span> `;
                }
                if(s.media_link) {
                    const isYT = extractYouTubeId(s.media_link);
                    resourceIcons += `<span class="inline-flex items-center gap-1 ${isYT ? 'bg-red-50 text-red-600 border-red-200' : 'bg-blue-50 text-blue-600 border-blue-200'} px-2 py-1 rounded-lg text-[10px] font-bold border" title="${s.media_link}"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>${isYT ? 'Video' : 'Link'}</span> `;
                }
                if(s.master_pages_covered) {
                    resourceIcons += `<span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 px-2 py-1 rounded-lg text-[10px] font-bold border border-amber-200" title="Pages: ${s.master_pages_covered}"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>Pg ${s.master_pages_covered}</span>`;
                }
                if(!resourceIcons) resourceIcons = '<span class="text-gray-300 text-xs">—</span>';

                sHtml += `
                <tr class="hover:bg-blue-50/30 transition-colors">
                    <td class="px-6 py-4 font-bold text-hodBlue whitespace-nowrap">${dateNice}</td>
                    <td class="px-6 py-4 font-bold text-gray-900">${s.topic}</td>
                    <td class="px-6 py-4 text-xs font-bold text-gray-600 whitespace-nowrap">${s.teacher_fname} ${s.teacher_lname}</td>
                    <td class="px-6 py-4 text-center"><span class="bg-green-50 text-green-700 px-3 py-1 rounded-lg font-bold text-xs border border-green-200">${s.kids_present} Present</span></td>
                    <td class="px-6 py-4 text-center"><div class="flex flex-wrap gap-1 justify-center">${resourceIcons}</div></td>
                    <td class="px-6 py-4 text-right whitespace-nowrap">
                        <button onclick="viewServiceDetails(${s.id})" class="text-xs bg-white border border-gray-200 hover:border-hodBlue text-gray-600 hover:text-hodBlue px-3 py-2 rounded-lg font-bold shadow-sm transition-colors mr-1" title="View Details">
                            <svg class="w-4 h-4 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </button>
                        <button onclick="viewAttendance(${s.id}, '${dateNice}', '${s.topic.replace(/'/g, "\\'")}', '${(s.master_pages_covered||'').replace(/'/g, "\\'")}')" class="text-xs bg-hodBlue hover:bg-blue-900 text-white px-4 py-2 rounded-lg font-bold shadow-sm transition-colors">Take Attendance</button>
                    </td>
                </tr>`;
            });
        }
        $('#servicesList').html(sHtml);
    }

    // ---------- CURRICULUMS ----------
    function renderCurriculums(res) {
        let cHtml = '';
        if(!res.curriculums || res.curriculums.length === 0) {
            cHtml = `<div class="col-span-full py-16 text-center">
                <div class="w-20 h-20 bg-purple-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                </div>
                <p class="text-gray-500 font-bold text-lg">No curriculums uploaded yet</p>
                <p class="text-gray-400 text-sm mt-1">Click "Upload Curriculum" to add your first master manual.</p>
            </div>`;
        } else {
            res.curriculums.forEach(cur => {
                const fType = getFileType(cur.file_path);
                const icon = getFileIcon(fType);
                const dateStr = new Date(cur.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const canProject = (fType === 'pdf' || fType === 'image');

                cHtml += `
                <div class="resource-card bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-start gap-4 mb-4">
                            <div class="w-14 h-14 bg-gray-50 rounded-2xl flex items-center justify-center shrink-0 border border-gray-100">${icon}</div>
                            <div class="min-w-0 flex-1">
                                <h4 class="text-base font-bold text-gray-900 truncate">${cur.title}</h4>
                                <p class="text-xs text-gray-500 mt-0.5 font-medium">${dateStr}</p>
                                ${cur.description ? `<p class="text-xs text-gray-600 mt-1 line-clamp-2">${cur.description}</p>` : ''}
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <a href="${cur.file_path}" download class="flex-1 flex items-center justify-center gap-2 bg-gray-50 hover:bg-gray-100 text-gray-700 py-2.5 rounded-xl text-xs font-bold transition-all border border-gray-200">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                Download
                            </a>
                            ${canProject ? `
                            <button onclick="openTheatre('${fType}', '${cur.file_path}')" class="flex-1 flex items-center justify-center gap-2 bg-purple-50 hover:bg-purple-100 text-purple-700 py-2.5 rounded-xl text-xs font-bold transition-all border border-purple-200">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                                Present
                            </button>` : ''}
                            <button onclick="deleteCurriculum(${cur.id}, '${cur.title.replace(/'/g, "\\'")}')" class="flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-500 py-2.5 px-3 rounded-xl text-xs font-bold transition-all border border-red-200" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>`;
            });
        }
        $('#curriculumGrid').html(cHtml);
    }

    // ---------- POPULATE DROPDOWNS ----------
    function populateDropdowns(res) {
        // Parent dropdowns (Select2 requires empty first option for placeholder)
        let pOpts = '<option value=""></option>';
        res.parents.forEach(p => pOpts += `<option value="${p.id}">${p.first_name} ${p.last_name} (${p.phone || 'N/A'})</option>`);
        $('#inpParent, #inpParent2').html(pOpts);

        // Initialize Select2 on parent dropdowns
        if($.fn.select2) {
            // Destroy existing instances first to avoid duplicates
            if($('#inpParent').hasClass('select2-hidden-accessible')) {
                $('#inpParent, #inpParent2').select2('destroy');
            }
            $('.select2-parents').select2({
                dropdownParent: $('#childModal'),
                placeholder: "Search parent (type 2+ chars)...",
                minimumInputLength: 2,
                allowClear: true
            });
        }

        // Teacher dropdown
        let tOpts = '<option value="">Select Teacher...</option>';
        res.teachers.forEach(t => tOpts += `<option value="${t.id}">${t.first_name} ${t.last_name}</option>`);
        $('#inpTeacher').html(tOpts);
    }

    // ==========================================
    // VIEW SERVICE DETAILS (MODAL)
    // ==========================================
    function viewServiceDetails(serviceId) {
        const s = globalData.services.find(x => x.id == serviceId);
        if(!s) return;

        const dateNice = new Date(s.service_date).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

        let bodyHtml = `
            <div class="space-y-4">
                <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100">
                    <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest mb-1">Topic</p>
                    <p class="text-lg font-bold text-gray-900">${s.topic}</p>
                    <p class="text-sm font-medium text-hodBlue mt-1">${dateNice}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Teacher</p>
                        <p class="text-sm font-bold text-gray-800">${s.teacher_fname} ${s.teacher_lname}</p>
                    </div>
                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Kids Present</p>
                        <p class="text-sm font-bold text-green-600">${s.kids_present}</p>
                    </div>
                </div>`;

        if(s.master_pages_covered) {
            bodyHtml += `
                <div class="bg-amber-50/50 p-3 rounded-xl border border-amber-200">
                    <p class="text-[10px] font-bold text-amber-600 uppercase tracking-widest mb-1">Curriculum Pages Covered</p>
                    <p class="text-sm font-bold text-amber-800">${s.master_pages_covered}</p>
                </div>`;
        }
        if(s.notes) {
            bodyHtml += `
                <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Internal Notes</p>
                    <p class="text-sm text-gray-700">${s.notes}</p>
                </div>`;
        }

        // Resources section
        if(s.service_file_path || s.media_link) {
            bodyHtml += `<div class="border-t border-gray-100 pt-4 mt-2 space-y-3">
                <p class="text-[10px] font-bold text-purple-500 uppercase tracking-widest">Resources</p>`;

            if(s.service_file_path) {
                const fType = getFileType(s.service_file_path);
                const canProject = (fType === 'pdf' || fType === 'image');
                bodyHtml += `
                    <div class="flex gap-2">
                        <a href="${s.service_file_path}" download class="flex-1 flex items-center justify-center gap-2 bg-purple-50 hover:bg-purple-100 text-purple-700 py-2.5 rounded-xl text-xs font-bold border border-purple-200 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            Download File
                        </a>
                        ${canProject ? `<button onclick="closeModal('serviceDetailModal'); openTheatre('${fType}', '${s.service_file_path}')" class="flex-1 flex items-center justify-center gap-2 bg-gray-900 hover:bg-gray-800 text-white py-2.5 rounded-xl text-xs font-bold transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            Project to Screen
                        </button>` : ''}
                    </div>`;
            }
            if(s.media_link) {
                const ytId = extractYouTubeId(s.media_link);
                if(ytId) {
                    bodyHtml += `
                    <div class="bg-black rounded-2xl overflow-hidden relative cursor-pointer group" onclick="closeModal('serviceDetailModal'); openTheatre('youtube', '${s.media_link}')">
                        <img src="https://img.youtube.com/vi/${ytId}/hqdefault.jpg" class="w-full h-40 object-cover opacity-80 group-hover:opacity-60 transition-opacity">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="w-14 h-14 bg-red-600 rounded-full flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                                <svg class="w-7 h-7 text-white ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            </div>
                        </div>
                        <div class="absolute bottom-3 left-3 bg-black/60 backdrop-blur-sm text-white text-xs font-bold px-3 py-1 rounded-lg">Watch in Theatre Mode</div>
                    </div>`;
                } else {
                    bodyHtml += `
                    <a href="${s.media_link}" target="_blank" class="flex items-center gap-3 bg-blue-50 hover:bg-blue-100 text-blue-700 p-3 rounded-xl text-sm font-bold border border-blue-200 transition-all">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        Open External Link
                    </a>`;
                }
            }
            bodyHtml += '</div>';
        }

        bodyHtml += '</div>';

        $('#svcDetailTitle').text(s.topic);
        $('#svcDetailBody').html(bodyHtml);
        openModal('serviceDetailModal');
    }

    // ==========================================
    // CHILD MODAL & CROPPER
    // ==========================================
    function openChildModal(id = null) {
        $('#childForm')[0].reset();
        $('#childDisplayPic').addClass('hidden');
        $('#childPicPlaceholder').removeClass('hidden');
        $('#inpChildId').val('');
        $('#inpExistingPic').val('');
        currentChildBase64 = '';

        // Clear Select2 dropdowns
        if($.fn.select2 && $('#inpParent').hasClass('select2-hidden-accessible')) {
            $('#inpParent, #inpParent2').val('').trigger('change');
        }

        if(id) {
            const c = globalData.children.find(x => x.id == id);
            if(c) {
                $('#childModalTitle').text('Edit Child Record');
                $('#inpChildId').val(c.id);
                $('#inpFname').val(c.child_first_name);
                $('#inpLname').val(c.child_last_name);
                $('#inpDob').val(c.dob);
                $('#inpParent').val(c.parent_id).trigger('change');
                $('#inpParent2').val(c.parent2_id).trigger('change');
                $('#inpMed').val(c.medical_notes);
                if(c.picture_path) {
                    $('#childDisplayPic').attr('src', c.picture_path).removeClass('hidden');
                    $('#childPicPlaceholder').addClass('hidden');
                    $('#inpExistingPic').val(c.picture_path);
                }
            }
        } else {
            $('#childModalTitle').text('Register Child');
        }
        openModal('childModal');
    }

    $('#childUploadInput').on('change', function(e) {
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
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.8,
                guides: false,
                center: true,
                highlight: false,
                toggleDragModeOnDblclick: false
            });
        };
        reader.readAsDataURL(file);
        $(this).val('');
    });

    $('#btnApplyCrop').on('click', function() {
        if(!cropper) return;
        const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
        currentChildBase64 = canvas.toDataURL('image/png');
        $('#childDisplayPic').attr('src', currentChildBase64).removeClass('hidden');
        $('#childPicPlaceholder').addClass('hidden');
        closeModal('cropModal');
    });

    // ==========================================
    // CURRICULUM UPLOAD MODAL
    // ==========================================
    function openCurriculumUploadModal() {
        $('#curriculumUploadForm')[0].reset();
        openModal('curriculumUploadModal');
    }

    function deleteCurriculum(id, title) {
        if(!confirm(`Are you sure you want to delete "${title}"? This action cannot be undone.`)) return;

        $.post(API_URL, { action: 'delete_curriculum', curriculum_id: id }, function(res) {
            showToast(res.message, res.status);
            if(res.status === 'success') loadDashboard();
        }, 'json');
    }

    // ==========================================
    // SERVICE MODAL
    // ==========================================
    function openServiceModal() {
        $('#serviceForm')[0].reset();
        openModal('serviceModal');
    }

    // ==========================================
    // ATTENDANCE ENGINE (Optimistic UI)
    // ==========================================
    function viewAttendance(serviceId, dateNice, topic, pagesCovered) {
        currentAttendanceServiceId = serviceId;

        $('#attSvcDate').text(dateNice);
        $('#attSvcTopic').text(topic);
        $('#attSvcPages').text(pagesCovered ? 'Curriculum: ' + pagesCovered : '');
        $('#attendanceGrid').html('<div class="col-span-full py-10 text-center text-gray-500">Loading roster...</div>');

        // Check for service resources
        const s = globalData.services.find(x => x.id == serviceId);
        let resBtns = '';
        if(s) {
            if(s.media_link) {
                const ytId = extractYouTubeId(s.media_link);
                if(ytId) {
                    resBtns += `<button onclick="openTheatre('youtube', '${s.media_link}')" class="flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-md transition-all">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        Play Video
                    </button>`;
                } else {
                    resBtns += `<a href="${s.media_link}" target="_blank" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-md transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        Open Link
                    </a>`;
                }
            }
            if(s.service_file_path) {
                const fType = getFileType(s.service_file_path);
                const canProject = (fType === 'pdf' || fType === 'image');
                resBtns += `<a href="${s.service_file_path}" download class="flex items-center gap-2 bg-purple-50 hover:bg-purple-100 text-purple-700 px-4 py-2.5 rounded-xl text-xs font-bold border border-purple-200 transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Download File
                </a>`;
                if(canProject) {
                    resBtns += `<button onclick="openTheatre('${fType}', '${s.service_file_path}')" class="flex items-center gap-2 bg-gray-900 hover:bg-gray-800 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-md transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        Project to Screen
                    </button>`;
                }
            }
        }
        if(resBtns) {
            $('#attResourcesBtns').html(resBtns);
            $('#attResourcesBar').removeClass('hidden');
        } else {
            $('#attResourcesBar').addClass('hidden');
        }

        // Switch view
        switchTab('');
        $('#view-roster, #view-services, #view-curriculum').addClass('hidden');
        $('#view-attendance').removeClass('hidden').addClass('animate-fade-in-up');

        // Fetch attendance data
        $.post(API_URL, { action: 'fetch_attendance', service_id: serviceId }, function(res) {
            if(res.status === 'success') {
                let aHtml = '';
                let presentCount = 0;
                res.attendance.forEach(c => {
                    if(c.status === 'Present') presentCount++;

                    const pic = c.picture_path
                        ? `<img src="${c.picture_path}" class="w-12 h-12 rounded-full object-cover border-2 border-white shadow-sm">`
                        : `<div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center font-bold text-blue-400 text-sm border-2 border-white shadow-sm">${c.child_first_name.charAt(0)}</div>`;

                    const isPresent = c.status === 'Present';
                    const btnClass = isPresent
                        ? 'bg-green-500 text-white border-green-600 shadow-md shadow-green-500/30'
                        : 'bg-white text-gray-400 border-gray-200 hover:border-gray-300';
                    const btnText = isPresent ? 'Present' : 'Absent';

                    // Medical alert badge
                    const medBadge = c.medical_notes
                        ? `<span class="inline-flex items-center bg-red-100 text-red-600 text-[9px] font-bold px-1.5 py-0.5 rounded-md med-pulse ml-1" title="${c.medical_notes}">
                            <svg class="w-3 h-3 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"></path></svg>MED
                        </span>`
                        : '';

                    // Parent quick-dial buttons
                    const parentPhone = c.parent_phone || '';
                    let quickDial = '';
                    if(parentPhone) {
                        quickDial = `
                        <div class="flex gap-1 mt-1">
                            <a href="tel:${parentPhone}" class="quick-dial-btn inline-flex items-center gap-0.5 text-blue-500 hover:text-blue-700 text-[10px] font-bold" title="Call ${c.parent_fname || 'Parent'}">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                                Call
                            </a>
                            <span class="text-gray-300">|</span>
                            <a href="https://wa.me/${parentPhone.replace(/[^0-9]/g, '')}" target="_blank" class="quick-dial-btn inline-flex items-center gap-0.5 text-green-500 hover:text-green-700 text-[10px] font-bold" title="WhatsApp ${c.parent_fname || 'Parent'}">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
                                WA
                            </a>
                        </div>`;
                    }

                    aHtml += `
                    <div class="flex items-center justify-between bg-gray-50 p-3 rounded-2xl border border-gray-100 hover:bg-gray-100/50 transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            ${pic}
                            <div class="min-w-0">
                                <p class="font-bold text-sm text-gray-900 truncate">${c.child_first_name} ${c.child_last_name}${medBadge}</p>
                                ${quickDial}
                            </div>
                        </div>
                        <button id="attBtn_${c.child_id}" onclick="toggleAttendance(${serviceId}, ${c.child_id}, ${isPresent})" class="px-4 py-2 rounded-xl text-xs font-bold transition-all border shrink-0 ml-2 ${btnClass}">
                            ${btnText}
                        </button>
                    </div>`;
                });
                $('#attendanceGrid').html(aHtml);
                $('#attTotalPresent').text(presentCount);
            }
        }, 'json');
    }

    function toggleAttendance(serviceId, childId, currentlyPresent) {
        const newStatus = currentlyPresent ? 'Absent' : 'Present';
        const btn = $(`#attBtn_${childId}`);

        // Optimistic UI Update
        if(newStatus === 'Present') {
            btn.removeClass('bg-white text-gray-400 border-gray-200 hover:border-gray-300')
               .addClass('bg-green-500 text-white border-green-600 shadow-md shadow-green-500/30')
               .text('Present');
            btn.attr('onclick', `toggleAttendance(${serviceId}, ${childId}, true)`);
            $('#attTotalPresent').text(parseInt($('#attTotalPresent').text()) + 1);
        } else {
            btn.addClass('bg-white text-gray-400 border-gray-200 hover:border-gray-300')
               .removeClass('bg-green-500 text-white border-green-600 shadow-md shadow-green-500/30')
               .text('Absent');
            btn.attr('onclick', `toggleAttendance(${serviceId}, ${childId}, false)`);
            $('#attTotalPresent').text(parseInt($('#attTotalPresent').text()) - 1);
        }
        // Silent background sync
        $.post(API_URL, { action: 'mark_attendance', service_id: serviceId, child_id: childId, status: newStatus });
    }

    // ==========================================
    // MARK ALL PRESENT (One-Click)
    // ==========================================
    function markAllPresent() {
        if(!currentAttendanceServiceId) return;

        // Optimistic UI: flip all buttons to Present
        $('[id^="attBtn_"]').each(function() {
            const btn = $(this);
            if(btn.text().trim() === 'Absent') {
                const onclickAttr = btn.attr('onclick');
                // Extract childId from onclick
                const match = onclickAttr.match(/toggleAttendance\(\d+,\s*(\d+)/);
                if(match) {
                    const childId = match[1];
                    btn.removeClass('bg-white text-gray-400 border-gray-200 hover:border-gray-300')
                       .addClass('bg-green-500 text-white border-green-600 shadow-md shadow-green-500/30')
                       .text('Present');
                    btn.attr('onclick', `toggleAttendance(${currentAttendanceServiceId}, ${childId}, true)`);
                }
            }
        });

        // Count total children
        const totalKids = $('[id^="attBtn_"]').length;
        $('#attTotalPresent').text(totalKids);

        // Background sync
        $.post(API_URL, { action: 'mark_all_present', service_id: currentAttendanceServiceId }, function(res) {
            if(res.status === 'success') {
                showToast('All children marked present!', 'success');
            }
        }, 'json');
    }

    // ==========================================
    // INIT ON DOCUMENT READY
    // ==========================================
    $(document).ready(function() {
        loadDashboard();

        // Child form uses serialized data (base64 image included manually)
        handleAjaxForm('childForm', function() {
            closeModal('childModal');
            loadDashboard();
        });

        // Service form uses FormData (file upload support)
        handleAjaxForm('serviceForm', function() {
            closeModal('serviceModal');
            loadDashboard();
        });

        // Curriculum upload form uses FormData
        handleAjaxForm('curriculumUploadForm', function() {
            closeModal('curriculumUploadModal');
            loadDashboard();
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>