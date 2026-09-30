<?php
// /modules/profile/index.php
require_once '../../includes/header.php'; 

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<!-- Geoapify Autocomplete API -->
<link rel="stylesheet" href="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/styles/minimal.css">
<script src="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/dist/index.min.js"></script>

<style>
  /* Match the Geoapify input to your Tailwind theme */
  .geoapify-autocomplete-input {
    width: 100%;
    padding: 0.75rem 1rem !important; /* matches px-4 py-3 */
    background-color: transparent !important;
    border: none !important;
    color: #111827 !important; /* text-gray-900 */
    font-weight: 700 !important; /* font-bold */
    outline: none !important;
  }
  .geoapify-autocomplete-items {
    border-radius: 0.75rem;
    overflow: hidden;
    margin-top: 4px;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
  }
</style>


<div class="max-w-5xl mx-auto space-y-8 pb-10">
    
    <div class="bg-gray-900 rounded-3xl p-6 md:p-10 shadow-2xl relative overflow-hidden flex flex-col sm:flex-row items-center gap-8 z-10">
        <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/20 rounded-full blur-[80px] -mr-20 -mt-20 pointer-events-none"></div>
        
        <div class="relative group shrink-0">
            <div class="w-32 h-32 rounded-3xl bg-gray-800 border-4 border-gray-700 overflow-hidden shadow-2xl relative z-10">
                <img id="userDisplayPic" src="/assets/images/default-avatar.png" alt="Profile" class="w-full h-full object-cover">
                
                <label for="imageUploadInput" class="absolute inset-0 bg-black/60 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300 cursor-pointer backdrop-blur-sm">
                    <svg class="w-8 h-8 text-white mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="text-[10px] font-bold text-white uppercase tracking-widest">Update</span>
                    <input type="file" id="imageUploadInput" accept="image/png, image/jpeg, image/jpg" class="hidden">
                </label>
            </div>
            <div id="picSpinner" class="absolute inset-0 bg-gray-900/80 rounded-3xl flex items-center justify-center z-20 hidden">
                <svg class="animate-spin h-8 w-8 text-blue-500" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            </div>
        </div>

        <div class="relative z-10 text-center sm:text-left">
            <h2 id="headerName" class="text-3xl font-display font-bold text-white tracking-tight mb-2">Loading Profile...</h2>
            <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                <span id="headerSpiritual" class="bg-blue-500/20 text-blue-400 border border-blue-500/30 px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider">STATUS</span>
                <span id="headerAttendance" class="bg-gray-800 text-gray-400 border border-gray-700 px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider">STATUS</span>
            </div>
        </div>
    </div>

    <form id="profileForm" class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
        <input type="hidden" name="action" value="update_profile">
        
        <div class="p-6 md:p-8 space-y-8">
            
            <div>
                <div class="flex justify-between items-end mb-4">
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-widest flex items-center gap-2">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        Core Identity (Locked)
                    </h3>
                    <button type="button" onclick="openModal('nameChangeModal')" class="text-xs font-bold text-hodBlue hover:text-blue-800 underline decoration-blue-200 underline-offset-4 transition-colors">Request Correction</button>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1.5">First Name</label>
                        <input type="text" id="inpFirstName" readonly class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-500 font-bold cursor-not-allowed outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1.5">Last Name</label>
                        <input type="text" id="inpLastName" readonly class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-500 font-bold cursor-not-allowed outline-none">
                    </div>
                    <div class="md:col-span-2">
                    <label class="block text-[11px] font-bold text-hodBlue uppercase mb-1.5 flex justify-between">
                        Official Church ID
                        <span class="text-gray-400 font-normal lowercase" id="displayChurchEmail">loading...</span>
                    </label>
                    <div class="relative">
                        <input type="email" id="inpEmail" readonly class="w-full px-4 py-3 bg-blue-50/50 border border-blue-100 rounded-xl text-hodBlue font-bold cursor-not-allowed outline-none">
                        <svg class="w-4 h-4 text-blue-300 absolute right-4 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    </div>
                </div>
                
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Personal Email (Gmail/Yahoo)</label>
                    <input type="email" name="real_email" id="inpRealEmail" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all">
                </div>
                </div>
            </div>

            <hr class="border-gray-100">

            <div>
                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    Contact & Demographics
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Phone Number</label>
                        <input type="tel" name="phone" id="inpPhone" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Date of Birth</label>
                        <input type="date" name="dob" id="inpDob" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all">
                    </div>
                   <div class="md:col-span-2 relative z-50">
                        <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Physical Address (Type to search)</label>
                        
                        <!-- Geoapify injects the input here -->
                        <div id="autocomplete-container" class="w-full bg-white border border-gray-200 rounded-xl focus-within:border-hodBlue focus-within:ring-1 focus-within:ring-hodBlue transition-all"></div>
                        
                        <!-- Hidden fields for the form submission -->
                        <input type="hidden" name="physical_address" id="inpAddress">
                        <input type="hidden" name="latitude" id="inpLat">
                        <input type="hidden" name="longitude" id="inpLng">
                    </div>
                    
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Marital Status</label>
                        <select name="marital_status" id="inpMarital" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all cursor-pointer appearance-none">
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Separated">Separated</option>
                            <option value="Divorced">Divorced</option>
                        </select>
                    </div>
                    
                    <div id="anniversaryContainer" class="hidden md:col-span-2 transition-all duration-300">
                        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-5 flex flex-col sm:flex-row gap-6 items-center sm:items-start">
                            
                            <div class="flex-1 w-full">
                                <label class="block text-[11px] font-bold text-blue-800 uppercase mb-1.5">Wedding Anniversary Date</label>
                                <input type="date" name="wedding_anniversary" id="inpAnniv" class="w-full px-4 py-3 bg-white border border-blue-200 rounded-xl text-blue-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all">
                                <p class="text-[10px] text-blue-600 mt-2 font-medium">Used by the Charis team to celebrate your special day.</p>
                            </div>

                            <div class="shrink-0 w-full sm:w-auto flex flex-col items-center">
                                <label class="block text-[11px] font-bold text-blue-800 uppercase mb-1.5 text-center w-full">Wedding Photo</label>
                                <div class="relative group w-32 h-32 sm:w-28 sm:h-28 rounded-xl bg-white border-2 border-dashed border-blue-300 overflow-hidden shadow-sm flex items-center justify-center">
                                    <img id="weddingDisplayPic" src="" alt="Wedding" class="w-full h-full object-cover hidden">
                                    
                                    <div id="weddingPlaceholder" class="text-center p-2">
                                        <svg class="w-6 h-6 text-blue-300 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L28 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        <span class="text-[9px] font-bold text-blue-400 uppercase">Upload</span>
                                    </div>
                                    
                                    <label for="weddingUploadInput" class="absolute inset-0 bg-blue-900/70 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300 cursor-pointer backdrop-blur-sm">
                                        <svg class="w-6 h-6 text-white mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path></svg>
                                        <span class="text-[9px] font-bold text-white uppercase tracking-widest">Update</span>
                                        <input type="file" id="weddingUploadInput" accept="image/png, image/jpeg, image/jpg" class="hidden">
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-gray-50 px-6 py-5 border-t border-gray-100 flex justify-end">
            <button type="submit" id="btnSaveProfile" class="bg-gray-900 hover:bg-black text-white px-8 py-3 rounded-xl font-bold shadow-lg transition-all flex items-center gap-2">
                Save Changes
            </button>
        </div>
    </form>

    <!-- ============================================================ -->
    <!-- SECURITY: CHANGE PASSWORD + SIGN-IN ACTIVITY                 -->
    <!-- ============================================================ -->
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden" id="securityCard">
        <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-red-700 flex items-center justify-center text-white shadow-sm shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Security &amp; Password</h3>
                <p class="text-xs text-gray-500 font-medium">Change your password and review where you are signed in.</p>
            </div>
        </div>

        <div id="forcedChangeBanner" class="hidden mx-6 mt-6 bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-4 text-sm font-semibold">
            An administrator has asked you to set a new password before you continue.
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-0">

            <!-- Change password form -->
            <form id="changePasswordForm" class="lg:col-span-3 p-6 space-y-5 lg:border-r border-gray-100" autocomplete="off">
                <input type="hidden" name="action" value="change_password">

                <div>
                    <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Current Password</label>
                    <div class="relative">
                        <input type="password" name="current_password" id="inpCurrentPw" required autocomplete="current-password"
                            class="w-full px-4 py-3 pr-12 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all"
                            placeholder="Enter the password you use today">
                        <button type="button" data-toggle-pw="inpCurrentPw" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 p-1" aria-label="Show password">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">New Password</label>
                        <div class="relative">
                            <input type="password" name="new_password" id="inpNewPw" required autocomplete="new-password"
                                class="w-full px-4 py-3 pr-12 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all"
                                placeholder="Choose a strong password">
                            <button type="button" data-toggle-pw="inpNewPw" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 p-1" aria-label="Show password">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-700 uppercase mb-1.5">Confirm New Password</label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="inpConfirmPw" required autocomplete="new-password"
                                class="w-full px-4 py-3 pr-12 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue focus:ring-1 focus:ring-hodBlue outline-none transition-all"
                                placeholder="Type it once more">
                            <button type="button" data-toggle-pw="inpConfirmPw" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 p-1" aria-label="Show password">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                        </div>
                        <p id="pwMatchNote" class="hidden text-[11px] font-bold mt-1.5"></p>
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[11px] font-bold text-gray-700 uppercase tracking-wider">Password Strength</span>
                        <span id="pwStrengthLabel" class="text-[11px] font-bold text-gray-400 uppercase">Waiting…</span>
                    </div>
                    <div class="h-2 w-full bg-gray-200 rounded-full overflow-hidden">
                        <div id="pwStrengthBar" class="h-full w-0 bg-gray-300 rounded-full transition-all duration-300"></div>
                    </div>
                    <ul id="pwRules" class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-[11px] font-semibold text-gray-500 pt-1"></ul>
                </div>

                <label class="flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox" name="sign_out_others" value="1" checked
                        class="mt-0.5 w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                    <span class="text-xs text-gray-600 font-medium leading-relaxed">
                        Sign out my other devices after changing the password.
                        <span class="block text-gray-400">Recommended if you think someone else has used your account.</span>
                    </span>
                </label>

                <button type="submit" class="w-full sm:w-auto bg-hodRed hover:bg-red-700 text-white px-8 py-3 rounded-xl font-bold shadow-lg shadow-red-500/20 transition-all flex items-center justify-center gap-2">
                    Update Password
                </button>
            </form>

            <!-- Sign-in activity -->
            <div class="lg:col-span-2 p-6 bg-gray-50/40 space-y-5">
                <div class="grid grid-cols-1 gap-3">
                    <div class="bg-white border border-gray-100 rounded-2xl p-4">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Last Sign-in</p>
                        <p id="secLastLogin" class="text-sm font-bold text-gray-900">—</p>
                        <p id="secLastIp" class="text-[11px] text-gray-400 font-medium mt-0.5"></p>
                    </div>
                    <div class="bg-white border border-gray-100 rounded-2xl p-4">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Password Last Changed</p>
                        <p id="secPwChanged" class="text-sm font-bold text-gray-900">—</p>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Signed-in Devices</p>
                        <span id="secSessionCount" class="text-[10px] font-bold text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full">0</span>
                    </div>
                    <div id="secSessionList" class="space-y-2 max-h-56 overflow-y-auto custom-scrollbar pr-1">
                        <p class="text-xs text-gray-400 font-medium">Loading…</p>
                    </div>
                    <button type="button" id="btnSignOutOthers"
                        class="mt-3 w-full border border-gray-300 hover:border-hodRed hover:text-hodRed text-gray-700 px-4 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">
                        Sign out all other devices
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="nameChangeModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-900">Request Identity Correction</h3>
            <button onclick="closeModal('nameChangeModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1.5 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="nameChangeForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="request_name_change">
            
            <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl text-sm text-blue-800 font-medium">
                To prevent identity errors, name changes require pastoral approval. Submit your correct legal or recognized name below.
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5">Correct Full Name *</label>
                <input type="text" name="correct_name" required placeholder="e.g., John Doe" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-gray-900 font-bold focus:border-hodBlue outline-none">
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5">Reason for Change</label>
                <textarea name="reason" rows="3" placeholder="e.g., Misspelled during registration, Marriage name change..." class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-gray-900 focus:border-hodBlue outline-none resize-none"></textarea>
            </div>
            
            <button type="submit" class="w-full bg-hodBlue hover:bg-blue-800 text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all">
                Submit Request to Admin
            </button>
        </form>
    </div>
</div>

<div id="cropModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-gray-900 rounded-3xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 shadow-2xl flex flex-col max-h-[90vh] border border-gray-700">
        <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center shrink-0">
            <h3 class="text-lg font-bold text-white">Adjust Portrait</h3>
            <button onclick="closeModal('cropModal')" class="text-gray-400 hover:text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="p-6 flex-1 flex items-center justify-center bg-black min-h-[300px]">
            <div class="w-full max-h-[500px]">
                <img id="imageToCrop" class="max-w-full block hidden">
            </div>
        </div>
        
        <div class="p-6 bg-gray-800 border-t border-gray-700 flex justify-end gap-3 shrink-0">
            <button onclick="closeModal('cropModal')" class="px-6 py-2.5 rounded-xl font-bold text-gray-300 hover:text-white transition-colors">Cancel</button>
            <button id="btnApplyCrop" class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-2.5 rounded-xl font-bold shadow-lg transition-all flex items-center gap-2">
                Apply & Save
            </button>
        </div>
    </div>
</div>

<script>
    const API_URL = '/api/profile_api.php';
    
    let addressAutocompleteWidget; // Global variable so we can set the value on profile load

function initAddressAutocomplete() {
    addressAutocompleteWidget = new autocomplete.GeocoderAutocomplete(
        document.getElementById("autocomplete-container"), 
        "7a189b607e9e4c4cbedf6ada291b6bc1", 
        { 
            placeholder: "Search for an address...",
            filter: { countrycode: ['ng'] } 
        }
    );

    // Listen for place selection
    addressAutocompleteWidget.on('select', (location) => {
        if (location) {
            document.getElementById('inpLat').value = location.properties.lat;
            document.getElementById('inpLng').value = location.properties.lon;
            document.getElementById('inpAddress').value = location.properties.formatted;
        } else {
            document.getElementById('inpLat').value = '';
            document.getElementById('inpLng').value = '';
            document.getElementById('inpAddress').value = '';
        }
    });

    // Prevent form submission if they press Enter while searching
    document.getElementById('autocomplete-container').addEventListener('keydown', function(e) { 
        if (e.key === 'Enter') e.preventDefault(); 
    });
}
    let cropper = null;
    let currentCropTarget = ''; // 'profile' or 'wedding'

    // ==========================================
    // UI CORE: MODALS & TOASTS
    // ==========================================
    function openModal(id) {
        $('body').css('overflow', 'hidden'); 
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        requestAnimationFrame(() => {
            m.classList.remove('opacity-0');
            m.children[0].classList.remove('scale-95');
        });
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('opacity-0');
        m.children[0].classList.add('scale-95'); 
        setTimeout(() => { 
            m.classList.add('hidden'); 
            const form = m.querySelector('form'); 
            if(form) form.reset(); 
            
            // Clean up cropper & inputs
            if(cropper && id === 'cropModal') { 
                cropper.destroy(); 
                cropper = null; 
                $('#imageToCrop').addClass('hidden'); 
                $('#imageUploadInput').val(''); 
                $('#weddingUploadInput').val('');
            }
            if ($('.fixed.inset-0:not(.hidden)').length === 0) $('body').css('overflow', '');
        }, 300);
    }

    function showToast(msg, type = 'success') {
        Toastify({
            text: msg,
            gravity: "top",
            position: "center",
            style: { 
                background: type === 'success' ? "#10B981" : (type === 'warning' ? "#F59E0B" : "#EF4444"),
                borderRadius: "12px",
                fontWeight: "bold",
                boxShadow: "0 10px 25px -5px rgba(0, 0, 0, 0.1)"
            }
        }).showToast();
    }

    // Ajax Form Handler with Spinner Lock
    function handleAjaxForm(formId, successCallback) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            const spinner = `<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-current inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
            
            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner + 'Processing...');
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
                showToast(res.message, res.status);
                if(res.status === 'success' && successCallback) successCallback(res);
            }, 'json');
        });
    }

    // ==========================================
    // MODULE LOGIC: PROFILE & DYNAMICS
    // ==========================================
    function loadProfile() {
        $.post(API_URL, { action: 'fetch_profile' }, function(res) {
            if(res.status === 'success') {
                const u = res.data;
                
                // Set Hero Area
                $('#headerName').text(`${u.first_name} ${u.last_name}`);
                $('#headerSpiritual').text(u.spiritual_status.replace(/_/g, ' '));
                $('#headerAttendance').text(u.attendance_status.replace(/_/g, ' '));
                if(u.picture_path) $('#userDisplayPic').attr('src', u.picture_path);

                // Set Read-Only Fields
                $('#inpFirstName').val(u.first_name);
                $('#inpLastName').val(u.last_name);
                // Update these lines inside loadProfile()
                $('#inpEmail').val(u.email);
                $('#displayChurchEmail').text(u.email);
                $('#inpRealEmail').val(u.real_email);

                // Set Editable Fields
                $('#inpPhone').val(u.phone);
                $('#inpAddress').val(u.physical_address); // Update the hidden form field
                
                // Update the visible Geoapify widget
                if (u.physical_address && addressAutocompleteWidget) {
                    addressAutocompleteWidget.setValue(u.physical_address);
                }
                
                $('#inpDob').val(u.dob);
                $('#inpMarital').val(u.marital_status || 'Single').trigger('change'); // Trigger to handle Anniversary logic
                if(u.wedding_anniversary) $('#inpAnniv').val(u.wedding_anniversary);
                
                // Load Wedding Picture if exists
                if(u.wedding_picture_path) {
                    $('#weddingDisplayPic').attr('src', u.wedding_picture_path).removeClass('hidden');
                    $('#weddingPlaceholder').addClass('hidden');
                }
            }
        }, 'json');
    }

    // Dynamic Marital Status Logic
    $('#inpMarital').on('change', function() {
        if ($(this).val() === 'Married') {
            $('#anniversaryContainer').removeClass('hidden');
        } else {
            $('#anniversaryContainer').addClass('hidden');
            $('#inpAnniv').val(''); // Clear it out
        }
    });

    // ==========================================
    // DYNAMIC IMAGE CROPPER ENGINE (Profile & Wedding)
    // ==========================================
    $('#imageUploadInput, #weddingUploadInput').on('change', function(e) {
        const file = e.target.files[0];
        if(!file) return;

        // Determine which button triggered the crop
        currentCropTarget = e.target.id === 'imageUploadInput' ? 'profile' : 'wedding';

        const reader = new FileReader();
        reader.onload = function(event) {
            const img = document.getElementById('imageToCrop');
            img.src = event.target.result;
            img.classList.remove('hidden');
            
            openModal('cropModal');

            // Initialize Cropper ensuring a perfect 1:1 Square (Portrait format)
            if(cropper) cropper.destroy();
            cropper = new Cropper(img, {
                aspectRatio: 1, 
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.8,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
            });
        };
        reader.readAsDataURL(file);
        $(this).val(''); // Reset input
    });

    $('#btnApplyCrop').on('click', function() {
        if(!cropper) return;
        
        const btn = $(this);
        const origHtml = btn.html();
        btn.prop('disabled', true).html(`<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...`);
        
        // Show spinner on main profile pic ONLY if updating profile
        if (currentCropTarget === 'profile') {
            $('#picSpinner').removeClass('hidden');
        }

        // Get the cropped image data as a Base64 string
        const canvas = cropper.getCroppedCanvas({ width: 600, height: 600 });
        const base64Image = canvas.toDataURL('image/png');

        const targetAction = currentCropTarget === 'profile' ? 'upload_picture' : 'upload_wedding_picture';

        // Send to backend
        $.post(API_URL, { action: targetAction, image_base64: base64Image }, function(res) {
            btn.prop('disabled', false).html(origHtml);
            $('#picSpinner').addClass('hidden');
            
            if(res.status === 'success') {
                showToast(res.message, 'success');
                
                if(currentCropTarget === 'profile') {
                    $('#userDisplayPic').attr('src', res.path);
                    // Also update the navbar avatar immediately if it exists
                    $('.nav-avatar-img').attr('src', res.path); 
                } else {
                    $('#weddingDisplayPic').attr('src', res.path).removeClass('hidden');
                    $('#weddingPlaceholder').addClass('hidden');
                }
                
                closeModal('cropModal');
            } else {
                showToast(res.message, 'error');
            }
        }, 'json');
    });

    // ==========================================
    // SECURITY: PASSWORD CHANGE + SIGNED-IN DEVICES
    // ==========================================
    const PW_MIN_LENGTH = 10;

    const PW_RULES = [
        { id: 'len',   label: `At least ${PW_MIN_LENGTH} characters`, test: v => v.length >= PW_MIN_LENGTH },
        { id: 'upper', label: 'One UPPERCASE letter',                 test: v => /[A-Z]/.test(v) },
        { id: 'lower', label: 'One lowercase letter',                 test: v => /[a-z]/.test(v) },
        { id: 'digit', label: 'One number',                           test: v => /[0-9]/.test(v) },
        { id: 'sym',   label: 'A symbol (optional, stronger)',        test: v => /[^A-Za-z0-9]/.test(v), optional: true }
    ];

    function escHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function prettyDate(raw) {
        if (!raw) return null;
        const d = new Date(String(raw).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(raw);
        return d.toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true
        });
    }

    function renderPwRules() {
        $('#pwRules').html(PW_RULES.map(r => `
            <li data-rule="${r.id}" class="flex items-center gap-2">
                <span class="rule-dot w-3.5 h-3.5 rounded-full border-2 border-gray-300 shrink-0"></span>
                <span class="rule-text text-gray-500">${r.label}</span>
            </li>`).join(''));
    }

    function evaluatePassword() {
        const value = $('#inpNewPw').val() || '';
        let requiredTotal = 0, requiredMet = 0, bonus = 0;

        PW_RULES.forEach(rule => {
            const ok = rule.test(value);
            if (rule.optional) {
                if (ok) bonus++;
            } else {
                requiredTotal++;
                if (ok) requiredMet++;
            }
            const li = $(`#pwRules li[data-rule="${rule.id}"]`);
            li.find('.rule-dot')
                .toggleClass('bg-emerald-500 border-emerald-500', ok)
                .toggleClass('border-gray-300', !ok);
            li.find('.rule-text')
                .toggleClass('text-emerald-700', ok)
                .toggleClass('text-gray-500', !ok);
        });

        let score = 0;
        if (value.length > 0) {
            score = (requiredMet / requiredTotal) * 70;
            if (value.length >= 14) score += 15;
            else if (value.length >= 12) score += 8;
            if (bonus) score += 15;
            score = Math.min(100, Math.round(score));
        }

        let label = 'Waiting…', colour = 'bg-gray-300', text = 'text-gray-400';
        if (value.length > 0) {
            if (requiredMet < requiredTotal) { label = 'Too weak';  colour = 'bg-red-500';     text = 'text-red-600'; }
            else if (score < 80)             { label = 'Good';      colour = 'bg-amber-500';   text = 'text-amber-600'; }
            else                             { label = 'Strong';    colour = 'bg-emerald-500'; text = 'text-emerald-600'; }
        }

        $('#pwStrengthBar').css('width', score + '%')
            .removeClass('bg-gray-300 bg-red-500 bg-amber-500 bg-emerald-500').addClass(colour);
        $('#pwStrengthLabel').text(label)
            .removeClass('text-gray-400 text-red-600 text-amber-600 text-emerald-600').addClass(text);

        checkPwMatch();
    }

    function checkPwMatch() {
        const a = $('#inpNewPw').val() || '';
        const b = $('#inpConfirmPw').val() || '';
        const note = $('#pwMatchNote');

        if (b.length === 0) { note.addClass('hidden'); return; }

        if (a === b) {
            note.removeClass('hidden text-red-600').addClass('text-emerald-600').text('Passwords match.');
        } else {
            note.removeClass('hidden text-emerald-600').addClass('text-red-600').text('Passwords do not match yet.');
        }
    }

    function resetPasswordForm() {
        $('#changePasswordForm')[0].reset();
        $('#changePasswordForm input[name="sign_out_others"]').prop('checked', true);
        $('#pwMatchNote').addClass('hidden');
        evaluatePassword();
    }

    function loadSecurity() {
        $.post(API_URL, { action: 'fetch_security' }, function(res) {
            if (res.status !== 'success') return;
            const d = res.data || {};

            $('#secLastLogin').text(prettyDate(d.last_login_at) || 'This is your first recorded sign-in');
            $('#secLastIp').text(d.last_login_ip ? `from ${d.last_login_ip}` : '');
            $('#secPwChanged').text(prettyDate(d.password_changed_at) || 'Not recorded yet');

            const sessions = d.sessions || [];
            $('#secSessionCount').text(sessions.length);

            if (!sessions.length) {
                $('#secSessionList').html('<p class="text-xs text-gray-400 font-medium">No other signed-in devices on record.</p>');
            } else {
                $('#secSessionList').html(sessions.map(s => `
                    <div class="bg-white border ${s.is_current ? 'border-emerald-200' : 'border-gray-100'} rounded-xl px-3 py-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-gray-900 truncate">${escHtml(s.device)}</span>
                            ${s.is_current ? '<span class="text-[9px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full uppercase shrink-0">This device</span>' : ''}
                        </div>
                        <p class="text-[10px] text-gray-400 font-medium mt-0.5">
                            ${escHtml(s.ip_address || 'unknown IP')} · last active ${escHtml(prettyDate(s.last_seen_at) || '—')}
                        </p>
                    </div>`).join(''));
            }

            if (d.must_change_password) {
                $('#forcedChangeBanner').removeClass('hidden');
            }
        }, 'json');
    }

    // Show / hide password toggles
    $(document).on('click', '[data-toggle-pw]', function() {
        const input = $('#' + $(this).data('toggle-pw'));
        const isHidden = input.attr('type') === 'password';
        input.attr('type', isHidden ? 'text' : 'password');
        $(this).toggleClass('text-hodBlue', isHidden).toggleClass('text-gray-400', !isHidden);
    });

    $(document).on('input', '#inpNewPw', evaluatePassword);
    $(document).on('input', '#inpConfirmPw', checkPwMatch);

    $(document).on('click', '#btnSignOutOthers', function() {
        if (!confirm('Sign out every other device signed in as you? This device stays signed in.')) return;

        const btn = $(this);
        btn.prop('disabled', true).addClass('opacity-60');

        $.post(API_URL, { action: 'sign_out_other_devices' }, function(res) {
            btn.prop('disabled', false).removeClass('opacity-60');
            showToast(res.message, res.status);
            if (res.status === 'success') loadSecurity();
        }, 'json');
    });

    // ==========================================
    // INITIALIZATION
    // ==========================================
    $(document).ready(function() {
        loadProfile();
        initAddressAutocomplete();
        renderPwRules();
        evaluatePassword();
        loadSecurity();

        // Main Form Save
        handleAjaxForm('profileForm');

        // Name Change Request Save
        handleAjaxForm('nameChangeForm', function() {
            closeModal('nameChangeModal');
        });

        // Password Change
        handleAjaxForm('changePasswordForm', function() {
            resetPasswordForm();
            $('#forcedChangeBanner').addClass('hidden');
            loadSecurity();
        });

        // Deep link from the nav / a forced-change redirect
        if (window.location.hash === '#security') {
            document.getElementById('securityCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
</script>

<?php require_once '../../includes/footer.php'; ?>