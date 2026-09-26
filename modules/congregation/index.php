<?php
// /modules/congregation/index.php
require_once '../../includes/header.php'; 
?>

<!-- Import SheetJS for Excel Exports -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<!-- Geoapify Autocomplete API -->
<link rel="stylesheet" href="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/styles/minimal.css">
<script src="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/dist/index.min.js"></script>

<style>
    /* Custom Geoapify styling to match your rounded-xl Tailwind forms */
    .geoapify-autocomplete-input {
        width: 100%;
        padding: 0.625rem 1rem !important; /* matches px-4 py-2.5 */
        background-color: transparent !important;
        border: none !important;
        color: #111827 !important; /* text-gray-900 */
        font-size: 0.875rem !important; /* text-sm */
        outline: none !important;
    }
    .geoapify-autocomplete-items {
        border-radius: 0.75rem;
        overflow: hidden;
        margin-top: 4px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        position: absolute;
        z-index: 9999;
    }
</style>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Congregation Data</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Master database, church health KPIs, and profile management.</p>
            </div>
        </div>
        
        <button onclick="openAddMemberModal()" class="relative z-10 bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all flex items-center gap-2 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Add New Member
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 relative z-10">
        <div onclick="viewKpiDetails('active_non_members', 'Active Non-Members')" class="bg-gradient-to-br from-orange-50 to-white p-6 rounded-3xl border border-orange-100 shadow-sm cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all group">
            <div class="flex justify-between items-start mb-2">
                <div class="w-10 h-10 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                </div>
                <span class="text-orange-600 text-xs font-bold uppercase tracking-wider bg-orange-100/50 px-2 py-1 rounded-lg">Potential Growth</span>
            </div>
            <h3 class="text-3xl font-black text-gray-900 mt-2" id="kpi-non-members">0</h3>
            <p class="text-sm text-gray-600 font-medium mt-1">Active Non-Members</p>
            <p class="text-[10px] text-orange-500 mt-3 font-bold flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">Click to view list <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></p>
        </div>

        <div onclick="viewKpiDetails('consistent_workers', 'Consistent Workers')" class="bg-gradient-to-br from-purple-50 to-white p-6 rounded-3xl border border-purple-100 shadow-sm cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all group">
            <div class="flex justify-between items-start mb-2">
                <div class="w-10 h-10 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                </div>
                <span class="text-purple-600 text-xs font-bold uppercase tracking-wider bg-purple-100/50 px-2 py-1 rounded-lg">Workforce</span>
            </div>
            <h3 class="text-3xl font-black text-gray-900 mt-2" id="kpi-workers">0</h3>
            <p class="text-sm text-gray-600 font-medium mt-1">Consistent Workers</p>
            <p class="text-[10px] text-purple-500 mt-3 font-bold flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">Click to view list <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></p>
        </div>

        <div onclick="viewKpiDetails('consistent_members', 'Consistent Members')" class="bg-gradient-to-br from-blue-50 to-white p-6 rounded-3xl border border-blue-100 shadow-sm cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all group">
            <div class="flex justify-between items-start mb-2">
                <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
                <span class="text-blue-600 text-xs font-bold uppercase tracking-wider bg-blue-100/50 px-2 py-1 rounded-lg">Core Base</span>
            </div>
            <h3 class="text-3xl font-black text-gray-900 mt-2" id="kpi-members">0</h3>
            <p class="text-sm text-gray-600 font-medium mt-1">Consistent Members</p>
            <p class="text-[10px] text-blue-500 mt-3 font-bold flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">Click to view list <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></p>
        </div>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative">
        <div class="p-6 border-b border-gray-100/60 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50/30">
            <h3 class="text-lg font-bold text-gray-800">Master Congregation Register</h3>
            <div class="flex items-center gap-3 w-full sm:w-auto">
                <div class="relative w-full sm:w-72">
                    <input type="text" id="searchRoster" placeholder="Search by name, phone..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all bg-white shadow-sm">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <button onclick="exportFilteredToExcel()" class="bg-green-50 hover:bg-green-100 text-green-700 border border-green-200 px-4 py-2.5 rounded-xl text-sm font-bold shadow-sm transition flex items-center gap-2 shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg> 
                    <span class="hidden sm:inline">Excel</span>
                </button>
            </div>
        </div>
        
        <div class="px-6 py-3 bg-white border-b border-gray-100/60 flex gap-3 overflow-x-auto custom-scrollbar items-center">
            <div class="text-xs font-bold text-gray-400 uppercase tracking-wider shrink-0 flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                <span class="hidden sm:inline">Filters</span>
            </div>
            <select id="filterTribe" class="bg-gray-50 border border-gray-200 text-gray-700 text-xs rounded-lg focus:ring-hodBlue focus:border-hodBlue block p-2 shrink-0">
                <option value="">🌍 All Tribes</option>
            </select>
            <select id="filterStatus" class="bg-gray-50 border border-gray-200 text-gray-700 text-xs rounded-lg focus:ring-hodBlue focus:border-hodBlue block p-2 shrink-0">
                <option value="">📊 All Status</option>
                <option value="Active">Active</option>
                <option value="New">New</option>
                <option value="Inconsistent">Inconsistent</option>
                <option value="Unknown">Unknown (AWOL)</option>
            </select>
            <select id="filterGrowth" class="bg-gray-50 border border-gray-200 text-gray-700 text-xs rounded-lg focus:ring-hodBlue focus:border-hodBlue block p-2 shrink-0">
                <option value="">🌱 All Growth</option>
                <option value="Visitor">Visitor</option>
                <option value="1st_Timer">1st Timer</option>
                <option value="Member">Member</option>
                <option value="Worker">Worker</option>
                <option value="Pastor" class="font-bold text-hodBlue">Pastor</option>
            </select>
            <select id="filterDept" class="bg-gray-50 border border-gray-200 text-gray-700 text-xs rounded-lg focus:ring-hodBlue focus:border-hodBlue block p-2 shrink-0">
                <option value="">🏢 All Depts</option>
            </select>
        </div>
        
        <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Profile</th>
                        <th class="px-6 py-4">Contact & Location</th>
                        <th class="px-6 py-4">Status & Growth</th>
                        <th class="px-6 py-4">Groups</th>
                        <th class="px-6 py-4 text-right">Manage</th>
                    </tr>
                </thead>
                <tbody id="rosterTableBody" class="divide-y divide-gray-50">
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <svg class="animate-spin h-8 w-8 text-hodBlue mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing congregation database...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="kpiDetailsModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex justify-end opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white w-full max-w-md h-full shadow-2xl flex flex-col transform translate-x-full transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <div>
                <h3 class="text-lg font-bold text-gray-900 leading-tight" id="kpiModalTitle">List</h3>
                <p class="text-xs text-gray-500 font-medium" id="kpiModalCount">0 People</p>
            </div>
            <button onclick="closeOffcanvas('kpiDetailsModal')" class="text-gray-400 hover:text-red-500 p-2"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="flex-1 overflow-y-auto p-4 bg-gray-50/50 custom-scrollbar space-y-2" id="kpiListContainer">
            </div>
    </div>
</div>

<div id="memberFormModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[80vh] md:max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        
        <div class="flex-shrink-0 px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-white z-10">
            <div>
                <h3 id="formModalTitle" class="text-xl md:text-2xl font-display font-bold text-gray-900 tracking-tight">Member Profile</h3>
                <p class="text-xs text-gray-500 mt-1 font-medium">Create or update master database record.</p>
            </div>
            <button onclick="closeModal('memberFormModal')" class="text-gray-400 hover:bg-red-50 hover:text-red-500 p-2 rounded-full transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar bg-gray-50/50">
            <form id="memberDataForm" class="space-y-6" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="create_member">
                <input type="hidden" name="user_id" id="formUserId" value="">

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm flex items-center gap-5">
                    <div class="relative w-20 h-20 rounded-full bg-gray-100 border border-gray-200 flex items-center justify-center overflow-hidden shrink-0" id="profilePicContainer">
                        <img id="profilePicPreview" src="" class="w-full h-full object-cover hidden">
                        <svg id="profilePicPlaceholder" class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Profile Photo (Optional)</label>
                        <input type="file" name="profile_pic" id="profilePicInput" accept="image/jpeg, image/png, image/jpg" class="block w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-5">
                    <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest border-b border-gray-50 pb-2">1. Core Identity</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">First Name *</label>
                            <input type="text" name="first_name" required class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm font-bold text-gray-900">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Last Name *</label>
                            <input type="text" name="last_name" required class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm font-bold text-gray-900">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Gender</label>
                            <select name="gender" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm bg-white cursor-pointer">
                                <option value="">Select...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Phone Number *</label>
                            <input type="tel" name="phone" required class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-hodBlue uppercase mb-1 flex justify-between">
                                Official Church Email 
                                <span class="text-gray-400 font-normal lowercase" id="displayChurchEmail">Auto-generated</span>
                            </label>
                            <input type="hidden" name="email" id="inpChurchEmail">
                            
                            <label class="block text-xs font-bold text-gray-600 uppercase mt-3 mb-1">Personal Email</label>
                            <input type="email" name="real_email" id="inpRealEmail" placeholder="e.g., name@gmail.com" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm shadow-sm bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Date of Birth</label>
                            <input type="date" name="dob" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm">
                        </div>
                        <div class="md:col-span-3 relative z-50">
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Physical Address (Type to search)</label>
                            
                            <!-- Geoapify Container -->
                            <div id="autocomplete-container" class="w-full bg-white border border-gray-200 rounded-xl focus-within:border-hodBlue transition-all shadow-sm"></div>
                            
                            <!-- Hidden fields for the actual form submission -->
                            <input type="hidden" name="physical_address" id="congregationAddress">
                            <input type="hidden" name="latitude" id="congregationLat">
                            <input type="hidden" name="longitude" id="congregationLng">
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-5">
                    <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest border-b border-gray-50 pb-2">2. Church & Attendance Status</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="bg-blue-50/40 p-4 rounded-xl border border-blue-100">
                            <label class="block text-xs font-bold text-blue-800 uppercase mb-2">Spiritual/Membership Status *</label>
                            <select name="spiritual_status" required class="w-full px-4 py-2.5 border border-white shadow-sm rounded-xl focus:border-blue-400 outline-none text-sm bg-white font-bold cursor-pointer">
                                <option value="Visitor">Visitor (General)</option>
                                <option value="1st_Timer">1st Timer</option>
                                <option value="2nd_Timer">2nd Timer</option>
                                <option value="3rd_Timer">3rd Timer</option>
                                <option value="Member">Full Member</option>
                                <option value="Worker">Worker</option>
                                <option value="Pastor" class="font-bold text-hodBlue">Pastor</option>
                                <option value="Non_Member">Non-Member</option>
                            </select>
                        </div>
                        <div class="bg-orange-50/40 p-4 rounded-xl border border-orange-100">
                            <label class="block text-xs font-bold text-orange-800 uppercase mb-2">Attendance Status *</label>
                            <select name="attendance_status" id="formAttendanceSelect" required class="w-full px-4 py-2.5 border border-white shadow-sm rounded-xl focus:border-orange-400 outline-none text-sm bg-white font-bold cursor-pointer">
                                <option value="New">New</option>
                                <option value="Active">Active</option>
                                <option value="Inconsistent">Inconsistent</option>
                                <option value="Unknown" class="text-red-500 font-bold">Unknown (AWOL)</option>
                                <option value="Relocated">Relocated</option>
                                <option value="Attends_Another_Church">Attends Another Church</option>
                            </select>
                            
                            <div id="new_church_div" class="hidden mt-3">
                                <label class="block text-[10px] font-bold text-orange-800 uppercase mb-1">Name of New Church</label>
                                <input type="text" name="new_church_name" placeholder="Where do they attend now?" class="w-full px-3 py-2 border border-white shadow-sm rounded-lg outline-none text-xs">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-5">
                    <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest border-b border-gray-50 pb-2">3. Welfare & Comments</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Marital Status</label>
                            <select name="marital_status" id="formMaritalSelect" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm bg-white cursor-pointer">
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Separated">Separated</option>
                                <option value="Divorced">Divorced</option>
                            </select>
                        </div>
                        <div id="anniversary_div" class="hidden">
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Wedding Anniversary</label>
                            <input type="date" name="wedding_anniversary" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm text-gray-700">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Pastoral Comments / Notes</label>
                            <textarea name="comments" rows="2" placeholder="Record important welfare notes, family details, etc." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm resize-none"></textarea>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex-shrink-0 px-6 py-5 border-t border-gray-100 bg-white flex justify-between items-center z-10">
            <button type="button" id="btnDeleteMember" class="hidden text-sm font-bold text-red-500 hover:text-red-700 hover:bg-red-50 px-4 py-2 rounded-lg transition-colors">Permanently Delete</button>
            <div class="flex gap-3 ml-auto">
                <button type="button" onclick="closeModal('memberFormModal')" class="px-6 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-100 transition-colors">Cancel</button>
                <button type="submit" form="memberDataForm" class="bg-hodBlue hover:bg-[#152750] text-white px-8 py-3 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all flex items-center justify-center gap-2">
                    Save Profile
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Attendance History Modal -->
<div id="attendanceHistoryModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex justify-end opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white w-full max-w-md h-full shadow-2xl flex flex-col transform translate-x-full transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 bg-gray-50 shrink-0">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h3 class="text-lg font-black text-gray-900 leading-tight" id="attHistoryName">Member Name</h3>
                    <p class="text-xs text-gray-500 font-medium">Attendance Health Report</p>
                </div>
                <button onclick="closeOffcanvas('attendanceHistoryModal')" class="text-gray-400 hover:text-red-500 p-1 bg-white rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            
            <select id="attHistoryPeriod" onchange="fetchAttendanceData()" class="w-full bg-white border border-gray-200 text-gray-700 text-sm font-bold rounded-xl focus:ring-hodBlue focus:border-hodBlue block p-3 shadow-sm outline-none cursor-pointer">
                <option value="3_months">Past 3 Months</option>
                <option value="6_months">Past 6 Months</option>
                <option value="1_year" selected>Past 1 Year</option>
                <option value="all_time">All Time</option>
            </select>
        </div>

        <div class="p-6 bg-white shrink-0 grid grid-cols-3 gap-3 border-b border-gray-100">
            <div class="bg-gray-50 p-3 rounded-xl text-center border border-gray-100">
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total</p>
                <h4 class="text-xl font-black text-gray-900" id="attSumTotal">-</h4>
            </div>
            <div class="bg-green-50 p-3 rounded-xl text-center border border-green-100">
                <p class="text-[9px] font-bold text-green-500 uppercase tracking-widest mb-1">Attended</p>
                <h4 class="text-xl font-black text-green-600" id="attSumPresent">-</h4>
            </div>
            <div class="bg-red-50 p-3 rounded-xl text-center border border-red-100">
                <p class="text-[9px] font-bold text-red-500 uppercase tracking-widest mb-1">Missed</p>
                <h4 class="text-xl font-black text-red-600" id="attSumMissed">-</h4>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6 bg-gray-50/50 custom-scrollbar space-y-3" id="attHistoryList">
            <!-- Records inject here -->
        </div>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-hodBlue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '/api/congregation_api.php';
    let rawKpiData = {};
    let masterRosterData = [];
    let congregationAddressWidget; // Global variable for Geoapify

    // ==========================================
    // UI CORE: MODALS & LOCKING (UPGRADED)
    // ==========================================

    // Helper to completely lock the screen during AJAX calls
    function lockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Lock scrolling
        setTimeout(() => blocker.classList.remove('opacity-0'), 10);
    }

    function unlockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.add('opacity-0');
        setTimeout(() => {
            blocker.classList.add('hidden');
            // Ensure we don't accidentally unlock if a modal is still open
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
        }, 300);
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        
        // VITAL: Move the modal to the body to escape ANY relative parent containers
        document.body.appendChild(modal); 
        
        const inner = modal.children[0];
        modal.classList.remove('hidden');
        
        // VITAL: Lock background scrolling
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => { 
            modal.classList.remove('opacity-0'); 
            inner.classList.remove('scale-95'); 
        }, 10);
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        
        const inner = modal.children[0];
        modal.classList.add('opacity-0'); 
        inner.classList.add('scale-95');
        
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            
            // PATCH: Only restore background scrolling if NO other overlays/blockers are active
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
            
            const form = modal.querySelector('form'); 
            if(form) form.reset(); 
        }, 300);
    }
    
    function closeOffcanvas(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
    
        const inner = modal.children[0];
        modal.classList.add('opacity-0'); 
        inner.classList.add('translate-x-full');
        
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            
            // PATCH: Apply the same safety check here
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
        }, 300);
    }

    function openOffcanvas(id) {
        const modal = document.getElementById(id);
        if(!modal) return;

        // VITAL: Move the offcanvas to the body to escape ANY relative parent containers
        document.body.appendChild(modal);

        const inner = modal.children[0];
        modal.classList.remove('hidden');
        
        // VITAL: Lock background scrolling
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => { 
            modal.classList.remove('opacity-0'); 
            inner.classList.remove('translate-x-full'); 
        }, 10);
    }

    function closeOffcanvas(id) {
        const modal = document.getElementById(id);
        if(!modal) return;

        const inner = modal.children[0];
        modal.classList.add('opacity-0'); 
        inner.classList.add('translate-x-full');
        
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            // VITAL: Restore background scrolling
            document.body.style.overflow = '';
        }, 300);
    }

    // Image Preview Logic
    $('#profilePicInput').on('change', function(e) {
        if(e.target.files && e.target.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#profilePicPlaceholder').addClass('hidden');
                $('#profilePicPreview').attr('src', e.target.result).removeClass('hidden');
            }
            reader.readAsDataURL(e.target.files[0]);
        }
    });

    // Standard AJAX Form Handler with Screen Lock (Upgraded for FormData)
    function handleAjaxForm(formId, successCallback) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            const spinner = `<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
            
            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner + 'Saving...');
            lockScreenAction(); // Apply global screen lock
            
            $.ajax({
                url: API_URL,
                type: 'POST',
                data: new FormData(this),
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
                    unlockScreenAction(); // Unlock the screen
                    
                    Toastify({ 
                        text: res.message, 
                        duration: 3000, 
                        gravity: "top", 
                        position: "center",
                        style: { 
                            background: res.status === 'success' ? "#10B981" : (res.status === 'warning' ? "#F59E0B" : "#EF4444"),
                            borderRadius: "10px", 
                            fontWeight: "bold",
                            boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
                        } 
                    }).showToast();
                    
                    if(res.status === 'success' && successCallback) successCallback(res);
                },
                error: function() {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
                    unlockScreenAction(); // Unlock the screen on error
                    
                    Toastify({ 
                        text: "Server Error.", 
                        duration: 3000, 
                        gravity: "top", 
                        position: "center", 
                        style: { 
                            background: "#EF4444",
                            borderRadius: "10px", 
                            fontWeight: "bold",
                            boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
                        } 
                    }).showToast();
                }
            });
        });
    }

    // ==========================================
    // MODULE LOGIC
    // ==========================================
    function loadDashboard() {
        $.post(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                // Update Dropdowns
                if (res.filters) {
                    if($('#filterTribe option').length <= 1) {
                        res.filters.tribes.forEach(t => $('#filterTribe').append(`<option value="${t.name}">${t.name}</option>`));
                        res.filters.departments.forEach(d => $('#filterDept').append(`<option value="${d.name}">${d.name}</option>`));
                    }
                }

                // Update KPIs
                rawKpiData = res.kpis;
                $('#kpi-non-members').text(res.kpis.active_non_members.length);
                $('#kpi-workers').text(res.kpis.consistent_workers.length);
                $('#kpi-members').text(res.kpis.consistent_members.length);
                masterRosterData = res.roster; // Save for CSV export

                // Update Master Roster
                let html = '';
                if(res.roster.length === 0) {
                    html = `<tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">Database is empty.</td></tr>`;
                } else {
                    res.roster.forEach(u => {
                        // Formatting logic
                        const isUnknown = u.attendance_status === 'Unknown';
                        const rowClass = isUnknown ? 'bg-red-50/30 hover:bg-red-50/60' : 'hover:bg-gray-50/50';
                        const nameColor = isUnknown ? 'text-red-700' : 'text-gray-900';
                        
                        let attBadge = `<span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-[10px] font-bold uppercase">${u.attendance_status.replace('_', ' ')}</span>`;
                        if (isUnknown) attBadge = `<span class="bg-red-500 text-white px-2 py-0.5 rounded text-[10px] font-bold uppercase animate-pulse">Unknown (AWOL)</span>`;
                        else if (u.attendance_status === 'Active') attBadge = `<span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Active</span>`;

                        // Push to Charis Button (Only for Unknowns)
                        const pushCharisBtn = isUnknown 
                            ? `<button onclick="pushToCharis(${u.id})" class="text-[10px] bg-red-100 hover:bg-red-600 hover:text-white text-red-700 px-2 py-1 rounded font-bold transition border border-red-200 whitespace-nowrap">Push to Charis</button>`
                            : '';

                        // Avatar generation
                        const avatarHtml = u.picture_path 
                            ? `<img src="${u.picture_path}" class="w-10 h-10 rounded-full object-cover border border-gray-200 shrink-0 shadow-sm" alt="Pic">` 
                            : `<div class="w-10 h-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs shrink-0 shadow-sm">${u.first_name.charAt(0)}${u.last_name.charAt(0)}</div>`;

                        html += `
                        <tr class="border-b border-gray-50 transition-colors ${rowClass}" data-tribe="${u.active_tribe || ''}" data-status="${u.attendance_status || ''}" data-growth="${u.spiritual_status || ''}" data-dept="${u.active_departments || ''}">
                            <td class="px-6 py-4 flex items-center gap-3">
                                ${avatarHtml}
                                <div>
                                    <p class="font-bold ${nameColor}">${u.first_name} ${u.last_name}</p>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1.5">${u.gender || '-'} • ${u.marital_status}</p>
                                    <button onclick="openAttendanceHistory(${u.id}, '${u.first_name.replace(/'/g, "\\'")} ${u.last_name.replace(/'/g, "\\'")}')" class="text-[9px] bg-blue-50 hover:bg-blue-100 text-hodBlue px-2 py-1 rounded-md font-bold transition-colors flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        Check Attendance
                                    </button>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-700 text-xs">${u.phone}</p>
                                <p class="text-[10px] text-gray-500 truncate max-w-[150px]">${u.physical_address || 'No address'}</p>
                            </td>
                            <td class="px-6 py-4 space-y-1">
                                <div class="font-bold text-xs text-hodBlue">${u.spiritual_status.replace('_', ' ')}</div>
                                <div>${attBadge}</div>
                            </td>
                            
                            <td class="px-6 py-4 align-top min-w-[150px]">
                                <div class="mb-2.5">
                                    <span class="block text-[9px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Tribe</span>
                                    <span class="block text-xs text-gray-800 font-bold leading-tight whitespace-normal">${u.active_tribe || 'None'}</span>
                                </div>
                                <div>
                                    <span class="block text-[9px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Departments</span>
                                    <span class="block text-xs text-gray-800 font-bold leading-tight whitespace-normal">${u.active_departments || 'None'}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    ${pushCharisBtn}
                                    <button onclick="openEditMemberModal(${u.id})" class="text-xs bg-white border border-gray-200 text-gray-700 hover:border-hodBlue hover:text-hodBlue px-3 py-1.5 rounded-lg font-bold shadow-sm transition">Edit</button>
                                </div>
                            </td>
                        </tr>`;
                    });
                }
                $('#rosterTableBody').html(html);
            } else {
                // If the backend returns an error (e.g., missing database table), show it here
                $('#rosterTableBody').html(`<tr><td colspan="5" class="px-6 py-10 text-center text-red-500 font-bold bg-red-50/50">Error: ${res.message}</td></tr>`);
            }
        }, 'json').fail(function(xhr) {
            // If the server crashes entirely (500 error), catch it here so it stops spinning
            $('#rosterTableBody').html(`<tr><td colspan="5" class="px-6 py-10 text-center text-red-500 font-bold bg-red-50/50">Server Error. Check console or PHP logs.</td></tr>`);
            console.error("Dashboard Load Error:", xhr.responseText);
        });
    }

    // View KPI Details Modal
    function viewKpiDetails(kpiKey, title) {
        const data = rawKpiData[kpiKey];
        $('#kpiModalTitle').text(title);
        $('#kpiModalCount').text(data.length + (data.length === 1 ? ' Person' : ' People'));

        let html = '';
        if(data.length === 0) {
            html = '<p class="text-center text-gray-400 text-sm py-10 italic">No records found for this metric.</p>';
        } else {
            data.forEach(p => {
                // Clean phone and map 0 to 234 for WhatsApp
                let cleanPhone = p.phone ? p.phone.replace(/\D/g, '') : '';
                if(cleanPhone.startsWith('0')) cleanPhone = '234' + cleanPhone.substring(1);

                const callBtn = p.phone 
                    ? `<a href="tel:${p.phone}" class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white p-2 rounded-lg transition-colors" title="Direct Call"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg></a>`
                    : `<span class="bg-gray-50 text-gray-300 p-2 rounded-lg cursor-not-allowed" title="No Phone"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg></span>`;
                
                const waBtn = p.phone 
                    ? `<a href="https://wa.me/${cleanPhone}" target="_blank" class="bg-[#25D366]/10 text-[#25D366] hover:bg-[#25D366] hover:text-white p-2 rounded-lg transition-colors" title="WhatsApp Message"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 0C5.385 0 .002 5.385.002 12.032c0 2.128.555 4.2 1.613 6.027L0 24l6.104-1.603c1.764.957 3.738 1.464 5.925 1.464 6.645 0 12.028-5.385 12.028-12.032C24.057 5.385 18.676 0 12.031 0zm0 21.84c-1.785 0-3.535-.48-5.064-1.385l-.364-.214-3.763.987.998-3.666-.236-.376C2.658 15.65 2.13 13.882 2.13 12.032 2.13 6.564 6.564 2.13 12.031 2.13c5.466 0 9.897 4.434 9.897 9.902 0 5.468-4.43 9.808-9.897 9.808zm5.426-7.404c-.297-.15-1.764-.87-2.037-.97-.27-.1-.47-.15-.668.15-.2.298-.77 1-.944 1.203-.175.204-.35.23-.648.08-.297-.15-1.258-.464-2.395-1.485-.886-.795-1.484-1.776-1.66-2.075-.174-.298-.018-.46.13-.61.134-.135.297-.348.446-.522.15-.175.2-.298.3-.497.1-.2.05-.376-.025-.522-.075-.15-.668-1.613-.916-2.208-.242-.58-.488-.503-.668-.513-.174-.01-.375-.01-.574-.01-.2 0-.524.075-.798.375-.274.3-.1047 1.17-.1047 2.855 0 1.685 1.07 3.315 1.22 3.515.15.2 2.4 3.664 5.816 5.14.814.35 1.45.56 1.946.717.818.26 1.56.223 2.146.135.654-.1 2.037-.833 2.324-1.637.288-.804.288-1.493.2-1.637-.088-.144-.336-.23-.634-.38z"/></svg></a>`
                    : '';

                html += `
                <div class="bg-white p-3 rounded-xl border border-gray-100 shadow-sm flex justify-between items-center hover:border-blue-200 transition-colors">
                    <div>
                        <p class="font-bold text-gray-900">${p.first_name} ${p.last_name}</p>
                        <p class="text-xs text-gray-500 font-medium">${p.spiritual_status ? p.spiritual_status.replace('_', ' ') : 'Unknown'}</p>
                    </div>
                    <div class="flex gap-2">
                        ${callBtn}
                        ${waBtn}
                    </div>
                </div>`;
            });
        }
        $('#kpiListContainer').html(html);
        openOffcanvas('kpiDetailsModal');
    }

    // CRUD Interactions
    function openAddMemberModal() {
        $('#memberDataForm')[0].reset();
        
        // Clear the visual Geoapify box
        if(congregationAddressWidget) congregationAddressWidget.setValue('');
        
        $('#formAction').val('create_member');
        $('#formUserId').val('');
        $('#formModalTitle').text('Add New Member');
        $('#btnDeleteMember').addClass('hidden'); // Hide delete button on create
        checkConditionalFields();
        openModal('memberFormModal');
    }
    
    // --- Attendance History Logic ---
    let currentAttUserId = null;

    function openAttendanceHistory(userId, memberName) {
        currentAttUserId = userId;
        $('#attHistoryName').text(memberName);
        
        // Reset view
        $('#attSumTotal, #attSumPresent, #attSumMissed').text('-');
        $('#attHistoryList').html(`<div class="text-center py-10"><svg class="animate-spin h-6 w-6 text-hodBlue mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><p class="text-xs font-bold text-gray-500">Fetching records...</p></div>`);
        
        openOffcanvas('attendanceHistoryModal');
        fetchAttendanceData();
    }

    function fetchAttendanceData() {
        if (!currentAttUserId) return;
        const period = $('#attHistoryPeriod').val();

        $.post(API_URL, { action: 'fetch_member_attendance', user_id: currentAttUserId, period: period }, function(res) {
            if (res.status === 'success') {
                // Update Summary
                $('#attSumTotal').text(res.summary.total_events);
                $('#attSumPresent').text(res.summary.present);
                $('#attSumMissed').text(res.summary.missed);

                // Build Record List
                let html = '';
                if (res.records.length === 0) {
                    html = '<div class="text-center py-10 text-sm font-medium text-gray-400 italic">No attendance records logged for this period.</div>';
                } else {
                    res.records.forEach(r => {
                        let badgeClass = r.status === 'Present' ? 'bg-green-100 text-green-700' : (r.status === 'Excused' ? 'bg-orange-100 text-orange-700' : 'bg-red-100 text-red-700');
                        let dateFormatted = new Date(r.event_date).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
                        
                        html += `
                        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex justify-between items-center">
                            <div>
                                <h4 class="font-bold text-gray-900 text-sm line-clamp-1">${r.title}</h4>
                                <p class="text-[10px] text-gray-500 font-medium uppercase mt-0.5">${r.event_category.replace('_', ' ')} • ${dateFormatted}</p>
                            </div>
                            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded ${badgeClass}">${r.status}</span>
                        </div>`;
                    });
                }
                $('#attHistoryList').html(html);
            } else {
                $('#attHistoryList').html(`<div class="text-center py-10 text-red-500 text-sm font-bold">${res.message}</div>`);
            }
        }, 'json');
    }

    function openEditMemberModal(id) {
        // PATCH: Safely capture the clicked button and apply localized loading state
        const btn = window.event ? window.event.currentTarget : null;
        if (btn) btn.outerHTML = `<span class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-400 border border-gray-100 shadow-sm">...</span>`;
    
        lockScreenAction(); // Apply global screen lock
    
        $.post(API_URL, { action: 'get_member', user_id: id }, function(res) {
            unlockScreenAction(); // Unlock global screen
            if (btn) loadDashboard(); // PATCH: Refresh table to restore original button HTML
            
            if(res.status === 'success') {
                const u = res.data;
                $('#formAction').val('update_member');
                $('#formUserId').val(u.id);
                $('#formModalTitle').text('Edit Profile: ' + u.first_name);
                
                // Reset File Input
                $('#profilePicInput').val('');
                if(u.picture_path) {
                    $('#profilePicPreview').attr('src', u.picture_path).removeClass('hidden');
                    $('#profilePicPlaceholder').addClass('hidden');
                } else {
                    $('#profilePicPreview').addClass('hidden');
                    $('#profilePicPlaceholder').removeClass('hidden');
                }

                // Populate Form
                $('input[name="first_name"]').val(u.first_name);
                $('input[name="last_name"]').val(u.last_name);
                $('input[name="phone"]').val(u.phone);
                $('#displayChurchEmail').text(u.email ? u.email : 'Auto-generated');
                $('#inpChurchEmail').val(u.email);
                $('#inpRealEmail').val(u.real_email);
                $('input[name="dob"]').val(u.dob);
                $('select[name="gender"]').val(u.gender);
                
                // Update hidden field AND visual widget
                $('#congregationAddress').val(u.physical_address);
                if (u.physical_address && congregationAddressWidget) {
                    congregationAddressWidget.setValue(u.physical_address);
                }
                
                $('select[name="spiritual_status"]').val(u.spiritual_status);
                $('select[name="attendance_status"]').val(u.attendance_status);
                $('input[name="new_church_name"]').val(u.new_church_name);
                $('select[name="marital_status"]').val(u.marital_status);
                $('input[name="wedding_anniversary"]').val(u.wedding_anniversary);
                $('textarea[name="comments"]').val(u.comments);
    
                $('#btnDeleteMember').removeClass('hidden').attr('onclick', `deleteMember(${u.id})`);
                checkConditionalFields();
                openModal('memberFormModal');
            } else {
                Toastify({ 
                    text: res.message, 
                    duration: 3000, 
                    gravity: "top", 
                    position: "center", 
                    style: { background: "#EF4444", borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
                }).showToast();
            }
        }, 'json');
    }
    
    function pushToCharis(id) {
        if(!confirm("Push this member to the Embrace/Charis module for an immediate welfare check?")) return;
        
        // PATCH: Restore inline button UX
        const btn = window.event ? window.event.currentTarget : null;
        if (btn) {
            btn.innerHTML = "Pushing...";
            btn.classList.add('opacity-50', 'pointer-events-none');
        }
    
        lockScreenAction(); // Apply global screen lock
    
        $.post(API_URL, { action: 'push_to_charis', user_id: id }, function(res) {
            unlockScreenAction();
            
            Toastify({ 
                text: res.message, 
                duration: 3000, 
                gravity: "top", 
                position: "center", 
                style: { background: res.status === 'success' ? "#10B981" : (res.status === 'warning' ? "#F59E0B" : "#EF4444"), borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
            }).showToast();
            
            loadDashboard(); // Refresh to reset buttons
        }, 'json');
    }

    function deleteMember(id) {
        if(!confirm("DANGER: Are you sure you want to permanently delete this member? All attendance and department records will be erased. This cannot be undone.")) return;
        
        lockScreenAction(); // Lock screen during deletion

        $.post(API_URL, { action: 'delete_member', user_id: id }, function(res) {
            unlockScreenAction();
            
            Toastify({ 
                text: res.message, 
                duration: 3000, 
                gravity: "top", 
                position: "center", 
                style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
            }).showToast();
            
            if(res.status === 'success') {
                closeModal('memberFormModal');
                loadDashboard();
            }
        }, 'json');
    }

    function pushToCharis(id) {
        if(!confirm("Push this member to the Embrace/Charis module for an immediate welfare check?")) return;
        
        lockScreenAction(); // Lock screen while pushing

        $.post(API_URL, { action: 'push_to_charis', user_id: id }, function(res) {
            unlockScreenAction();
            
            Toastify({ 
                text: res.message, 
                duration: 3000, 
                gravity: "top", 
                position: "center", 
                style: { background: res.status === 'success' ? "#10B981" : (res.status === 'warning' ? "#F59E0B" : "#EF4444"), borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
            }).showToast();
            
            loadDashboard(); // Refresh to reset buttons
        }, 'json');
    }

    // Dynamic UI Conditional Logic
    function checkConditionalFields() {
        if($('#formMaritalSelect').val() === 'Married') $('#anniversary_div').removeClass('hidden');
        else $('#anniversary_div').addClass('hidden');

        if($('#formAttendanceSelect').val() === 'Attends_Another_Church') $('#new_church_div').removeClass('hidden');
        else $('#new_church_div').addClass('hidden');
    }

    $('#formMaritalSelect, #formAttendanceSelect').on('change', checkConditionalFields);
    
    // ==========================================
    // EXCEL EXPORT ENGINE (SheetJS)
    // ==========================================
    function exportFilteredToExcel() {
        if(!masterRosterData || masterRosterData.length === 0) {
            alert("Database is empty. Nothing to export."); return;
        }

        // 1. Capture current UI filters
        const searchVal = $('#searchRoster').val().toLowerCase();
        const tribeVal = $('#filterTribe').val().toLowerCase();
        const statusVal = $('#filterStatus').val().toLowerCase();
        const growthVal = $('#filterGrowth').val().toLowerCase();
        const deptVal = $('#filterDept').val().toLowerCase();

        // 2. Filter raw data exactly like the UI
        const filteredData = masterRosterData.filter(u => {
            const text = `${u.first_name} ${u.last_name} ${u.phone} ${u.email}`.toLowerCase();
            const tribeData = (u.active_tribe || '').toLowerCase();
            const statusData = (u.attendance_status || '').toLowerCase();
            const growthData = (u.spiritual_status || '').toLowerCase();
            const deptData = (u.active_departments || '').toLowerCase();

            const mSearch = text.indexOf(searchVal) > -1;
            const mTribe = tribeVal === '' || tribeData.indexOf(tribeVal) > -1;
            const mStatus = statusVal === '' || statusData === statusVal;
            const mGrowth = growthVal === '' || growthData.indexOf(growthVal) > -1;
            const mDept = deptVal === '' || deptData.indexOf(deptVal) > -1;

            return mSearch && mTribe && mStatus && mGrowth && mDept;
        });

        if(filteredData.length === 0) {
            alert("No records match your current filters."); return;
        }

        // 3. Map Data into Clean Columns (Including exact column names you requested)
        const excelRows = filteredData.map((u, index) => {
            let updatedDate = '';
            if(u.updated_at) {
                updatedDate = new Date(u.updated_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
            }

            return {
                "S/N": index + 1,
                "Full Name": `${u.first_name} ${u.last_name}`,
                "Physical Address": u.physical_address || '',
                "Phone Number": u.phone || '',
                "Gender": u.gender || '',
                "Spiritual Status": (u.spiritual_status || '').replace('_', ' '),
                "Attendance Status": (u.attendance_status || '').replace('_', ' '),
                "Updated At": updatedDate,
                "Departments": u.active_departments || '',
                "Tribe": u.active_tribe || ''
            };
        });

        // 4. Create Workbook & Worksheet
        const worksheet = XLSX.utils.json_to_sheet(excelRows);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, "Congregation Data");

        // 5. Automatically format Column Widths for perfect readability!
        worksheet['!cols'] = [
            { wch: 5 },   // S/N
            { wch: 25 },  // Full Name
            { wch: 45 },  // Physical Address (Extra wide)
            { wch: 15 },  // Phone
            { wch: 10 },  // Gender
            { wch: 20 },  // Spiritual Status
            { wch: 20 },  // Attendance Status
            { wch: 15 },  // Updated At
            { wch: 35 },  // Departments
            { wch: 15 }   // Tribe
        ];

        // 6. Trigger Download natively as .xlsx
        const fileName = `HOD_Congregation_Export_${new Date().toISOString().slice(0,10)}.xlsx`;
        XLSX.writeFile(workbook, fileName);
    }
    
    function initCongregationAutocomplete() {
        const container = document.getElementById("autocomplete-container");
        if (!container) return;

        congregationAddressWidget = new autocomplete.GeocoderAutocomplete(
            container, 
            "7a189b607e9e4c4cbedf6ada291b6bc1", 
            { 
                placeholder: "Start typing street or estate...",
                filter: { countrycode: ['ng'] } 
            }
        );

        congregationAddressWidget.on('select', (location) => {
            if (location) {
                document.getElementById('congregationLat').value = location.properties.lat;
                document.getElementById('congregationLng').value = location.properties.lon;
                document.getElementById('congregationAddress').value = location.properties.formatted;
            } else {
                document.getElementById('congregationLat').value = '';
                document.getElementById('congregationLng').value = '';
                document.getElementById('congregationAddress').value = '';
            }
        });

        // Prevent Enter from accidentally submitting the modal
        container.addEventListener('keydown', function(e) { 
            if (e.key === 'Enter') e.preventDefault(); 
        });
    }
    
    // Initialization
    $(document).ready(function() {
        loadDashboard();
        initCongregationAutocomplete();
        
        handleAjaxForm('memberDataForm', function() {
            closeModal('memberFormModal');
            loadDashboard();
        });

        function applyMultiFilters() {
            const searchVal = $('#searchRoster').val().toLowerCase();
            const tribeVal = $('#filterTribe').val().toLowerCase();
            const statusVal = $('#filterStatus').val().toLowerCase();
            const growthVal = $('#filterGrowth').val().toLowerCase();
            const deptVal = $('#filterDept').val().toLowerCase();

            $('#rosterTableBody tr').filter(function() {
                if($(this).find('td').attr('colspan')) return; 
                
                const text = $(this).text().toLowerCase();
                const tribeData = ($(this).data('tribe') || '').toLowerCase();
                const statusData = ($(this).data('status') || '').toLowerCase();
                const growthData = ($(this).data('growth') || '').toLowerCase();
                const deptData = ($(this).data('dept') || '').toLowerCase();

                const mSearch = text.indexOf(searchVal) > -1;
                const mTribe = tribeVal === '' || tribeData.indexOf(tribeVal) > -1;
                const mStatus = statusVal === '' || statusData === statusVal;
                const mGrowth = growthVal === '' || growthData.indexOf(growthVal) > -1;
                const mDept = deptVal === '' || deptData.indexOf(deptVal) > -1;

                $(this).toggle(mSearch && mTribe && mStatus && mGrowth && mDept);
            });
        }

        $('#searchRoster').on('keyup', applyMultiFilters);
        $('#filterTribe, #filterStatus, #filterGrowth, #filterDept').on('change', applyMultiFilters);
    });
</script>

<?php require_once '../../includes/footer.php'; ?>