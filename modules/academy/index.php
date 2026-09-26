<?php
// /modules/academy/index.php
require_once '../../includes/header.php'; 
?>

<style>
    @media print {
        body * { visibility: hidden; }
        #printableResultSlip, #printableResultSlip * { visibility: visible; }
        #printableResultSlip { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
    }
</style>

<div class="max-w-7xl mx-auto space-y-8 pb-10 relative">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-yellow-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -ml-20 -mb-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">HOD Academy</h2>
                <p id="academySubtitle" class="text-gray-500 text-sm md:text-base mt-1 font-light">Loading your secure workspace...</p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative min-h-[600px] flex flex-col">
        
        <div id="loadingOverlay" class="absolute inset-0 bg-white/95 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
            <svg class="animate-spin h-10 w-10 text-hodBlue mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing secure academy records...</p>
        </div>

        <div id="state-not-applied" class="hidden p-6 md:p-10 animate-fade-in-up">
            <div class="max-w-4xl mx-auto">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-display font-bold text-gray-900 mb-4">Welcome to HOD Academy</h2>
                    <p class="text-gray-600">To begin your journey towards becoming a Worker at Household of David, please fill out this comprehensive application form.</p>
                </div>
                
                <form id="academyApplicationForm" class="bg-gray-50/50 p-8 rounded-3xl border border-gray-100 shadow-sm space-y-6">
                    <input type="hidden" name="action" value="submit_application">
                    
                    <h4 class="font-bold text-hodBlue border-b border-gray-200 pb-2 text-lg">1. Personal & Contact Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Full Name *</label><input type="text" name="full_name" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Phone Number *</label><input type="text" name="phone_number" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">WhatsApp Number</label><input type="text" name="whatsapp_number" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Date of Birth</label><input type="date" name="birth_date" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Email Address</label><input type="email" name="email_address" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Marital Status</label>
                            <select name="marital_status" onchange="toggleAnniversary(this.value)" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium">
                                <option value="Single">Single</option><option value="Married">Married</option><option value="Other">Other</option>
                            </select>
                        </div>
                        <div id="anniversaryField" class="hidden"><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Wedding Anniversary</label><input type="date" name="wedding_anniversary" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Residential Address</label><input type="text" name="residential_address" placeholder="Full residential address..." class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Occupation / School</label><input type="text" name="occupation" placeholder="Your profession or school" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Work / School Address</label><input type="text" name="work_address" placeholder="Address of work or school..." class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                    </div>
                    
                    <h4 class="font-bold text-hodBlue border-b border-gray-200 pb-2 pt-4 text-lg">2. Spiritual Background</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Salvation Date & Place</label><input type="text" name="salvation_info" placeholder="e.g., Feb 2024 / Douala" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Water Baptism Date & Place</label><input type="text" name="baptism_info" placeholder="e.g., May 2024 / Douala" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Holy Ghost Baptism Date & Place</label><input type="text" name="holy_spirit_info" placeholder="e.g., Aug 2025 / Lagos" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Previous Church</label><input type="text" name="previous_church" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Who Invited You?</label><input type="text" name="invited_by" placeholder="e.g., Sis Chioma" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Tribe / Captain</label><input type="text" name="tribe_captain" placeholder="e.g., Sis Dera" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Other Ministries Currently</label><input type="text" name="other_ministries" placeholder="Are you currently serving elsewhere?" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white"></div>
                    </div>

                    <h4 class="font-bold text-hodBlue border-b border-gray-200 pb-2 pt-4 text-lg">3. Service & Intentions</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Past Service Experience</label><textarea name="past_service_experience" rows="2" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue resize-none bg-white"></textarea></div>
                        <div><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Intended Service Department (Interests)</label><textarea name="service_interests" rows="2" placeholder="e.g., Sound System, Choir" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue resize-none bg-white"></textarea></div>
                        <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-600 uppercase mb-2">Why Household of David?</label><textarea name="why_hod" rows="2" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue resize-none bg-white"></textarea></div>
                    </div>

                    <h4 class="font-bold text-hodBlue border-b border-gray-200 pb-2 pt-4 text-lg">4. Availability & Comments</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Available Class Session (Month & Year)</label>
                            <input type="month" name="class_date" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium text-gray-700">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Comments / Questions</label>
                            <textarea name="comments" rows="2" placeholder="Any specific questions for the HODA team?" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue resize-none bg-white"></textarea>
                        </div>
                    </div>

                    <div class="pt-6 border-t border-gray-200 flex justify-end">
                        <button type="submit" class="bg-hodBlue hover:bg-[#152750] text-white px-10 py-4 rounded-xl font-bold text-lg transition-all shadow-lg w-full md:w-auto">Submit Official Application</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="state-application-pending" class="hidden p-10 text-center animate-fade-in-up">
            <div class="w-24 h-24 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <h2 class="text-3xl font-display font-bold text-gray-900 mb-4">Application Under Review</h2>
            <p class="text-gray-600 max-w-lg mx-auto">Your application has been successfully submitted to the Head of Department's office. You will be notified once you are approved and assigned to a cohort.</p>
        </div>


        <div id="academyNavigation" class="hidden border-b border-gray-100/80 bg-gray-50/30 px-6 pt-2">
            <nav class="flex space-x-6 overflow-x-auto custom-scrollbar" aria-label="Tabs">
                
                <button id="tab-btn-admin-merit" onclick="switchTab('admin-merit')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-hodBlue font-bold text-sm text-hodBlue transition-all">Cohort & Merit Manager</button>
                <button id="tab-btn-admin-grading" onclick="switchTab('admin-grading')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Grading Room</button>
                <button id="tab-btn-admin-setup" onclick="switchTab('admin-setup')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Architecture Setup</button>
                <button id="tab-btn-admin-curriculum" onclick="switchTab('admin-curriculum')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Curriculum & Exams</button>

                <button id="tab-btn-student-dash" onclick="switchTab('student-dash')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-hodBlue font-bold text-sm text-hodBlue transition-all">My Noticeboard</button>
                <button id="tab-btn-student-study" onclick="switchTab('student-study')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Study & Assignments</button>
                <button id="tab-btn-student-exam" onclick="switchTab('student-exam')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Examination Portal</button>
                <button id="tab-btn-student-result" onclick="switchTab('student-result')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-green-600 hover:text-green-800 transition-all">My Result Slip</button>
            </nav>
        </div>

        <div id="academyContentArea" class="hidden p-6 md:p-8 flex-1 bg-white">
            
            <div id="tab-content-admin-merit" class="hidden space-y-6 animate-fade-in-up">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-blue-50/50 p-6 rounded-3xl border border-blue-100">
                    <div>
                        <h3 class="text-xl font-display font-bold text-gray-900">Order of Merit & Final Panels</h3>
                        <p class="text-sm text-gray-600">Select a batch to conduct final interviews and process graduations.</p>
                    </div>
                    <select id="adminBatchSelect" class="w-full md:w-64 px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-white font-semibold text-hodBlue" onchange="loadBatchMeritList(this.value)">
                        <option value="">Loading Batches...</option>
                    </select>
                </div>

                <div id="meritListArea" class="hidden overflow-x-auto border border-gray-100 rounded-2xl shadow-sm bg-white">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                            <tr>
                                <th class="px-6 py-4">Rank</th>
                                <th class="px-6 py-4">Student</th>
                                <th class="px-6 py-4 text-center">Attendance</th>
                                <th class="px-6 py-4 text-center">Exam Score</th>
                                <th class="px-6 py-4 text-center">Panel Score</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="adminMeritTableBody" class="divide-y divide-gray-50"></tbody>
                    </table>
                </div>
            </div>

            <div id="tab-content-admin-grading" class="hidden space-y-6 animate-fade-in-up">
                <h3 class="text-xl font-display font-bold text-gray-900 mb-4">Pending Submissions</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="gradingRoomContainer">
                    </div>
            </div>

            <div id="tab-content-admin-setup" class="hidden space-y-8 animate-fade-in-up">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-purple-50/50 p-6 rounded-3xl border border-purple-100 shadow-sm">
                        <h3 class="text-xl font-display font-bold text-purple-900 mb-4">1. Master Programs</h3>
                        <form id="createProgramForm" class="space-y-4">
                            <input type="hidden" name="action" value="create_program">
                            <input type="text" name="name" required placeholder="Program Name (e.g., Membership Class)" class="w-full px-4 py-3 border border-purple-200 rounded-xl outline-none bg-white">
                            <textarea name="description" rows="2" placeholder="Description" class="w-full px-4 py-3 border border-purple-200 rounded-xl outline-none bg-white resize-none"></textarea>
                            <button type="submit" class="bg-purple-600 hover:bg-purple-800 text-white px-8 py-3 rounded-xl font-bold w-full transition-all">Create Program</button>
                        </form>
                    </div>
                    <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                        <h3 class="text-xl font-display font-bold text-gray-900 mb-4">2. Cohorts (Batches)</h3>
                        <form id="createBatchForm" class="space-y-4">
                            <input type="hidden" name="action" value="create_batch">
                            <select name="program_id" id="setupProgramSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-gray-50 focus:bg-white"></select>
                            <input type="text" name="batch_name" required placeholder="Batch Name (e.g., Nov/Dec 2025)" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-gray-50 focus:bg-white">
                            <button type="submit" class="bg-hodBlue hover:bg-[#152750] text-white px-8 py-3 rounded-xl font-bold w-full transition-all">Open Cohort</button>
                        </form>
                    </div>
                    <div class="bg-green-50/50 p-6 rounded-3xl border border-green-100 shadow-sm col-span-full">
                        <h3 class="text-xl font-display font-bold text-green-900 mb-4">3. Pending Student Applications</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-gray-600">
                                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase text-[10px]">
                                    <tr><th class="px-4 py-3">Applicant Name</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Assign To Batch</th><th class="px-4 py-3">Action</th></tr>
                                </thead>
                                <tbody id="pendingAppsTableBody" class="divide-y divide-gray-100"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-content-admin-curriculum" class="hidden space-y-8 animate-fade-in-up">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                        <h3 class="text-xl font-display font-bold text-gray-900 mb-4">Create Course Engine</h3>
                        <form id="createCourseForm" class="space-y-4">
                            <input type="hidden" name="action" value="create_course">
                            <select name="program_id" id="courseProgramSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-gray-50 focus:bg-white"></select>
                            
                            <input type="text" name="name" required placeholder="Course Name (e.g., Holy Spirit)" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-gray-50 focus:bg-white">
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="md:col-span-1">
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Facilitator</label>
                                    <select name="instructor_id" id="courseInstructorSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-white"></select>
                                </div>
                                <div class="md:col-span-1">
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Start Date</label>
                                    <input type="date" name="start_date" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-white text-sm text-gray-500">
                                </div>
                                <div class="md:col-span-1">
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">End Date</label>
                                    <input type="date" name="end_date" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-white text-sm text-gray-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <input type="number" name="timer_minutes" value="30" placeholder="Exam Timer (Mins)" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-gray-50 focus:bg-white">
                                <input type="number" name="passing_percentage" value="70" placeholder="Pass %" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-gray-50 focus:bg-white">
                            </div>
                            <button type="submit" class="bg-gray-800 hover:bg-black text-white px-8 py-3 rounded-xl font-bold w-full transition-all">Create Course Engine</button>
                        </form>
                    </div>

                    <div class="bg-blue-50/50 p-6 rounded-3xl border border-blue-100 shadow-sm">
                        <h3 class="text-xl font-display font-bold text-hodBlue mb-4">Upload Course Material</h3>
                        <form id="addMaterialForm" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="action" value="add_material">
                            <select name="course_id" id="materialCourseSelect" required class="w-full px-4 py-3 border border-blue-200 rounded-xl outline-none bg-white"></select>
                            <input type="text" name="title" required placeholder="Material Title" class="w-full px-4 py-3 border border-blue-200 rounded-xl outline-none bg-white">
                            <select name="material_type" required class="w-full px-4 py-3 border border-blue-200 rounded-xl outline-none bg-white" onchange="toggleUploadType(this.value)">
                                <option value="Document">Upload PDF / Document</option>
                                <option value="Link">YouTube / Web Link</option>
                            </select>
                            <div id="uploadInputArea">
                                <input type="file" name="file_upload" accept=".pdf,.doc,.docx" required class="w-full px-4 py-2 border border-blue-200 rounded-xl outline-none bg-white">
                            </div>
                            <button type="submit" class="bg-hodBlue hover:bg-[#152750] text-white px-8 py-3 rounded-xl font-bold w-full transition-all">Publish Material</button>
                        </form>
                    </div>

                    <div class="bg-yellow-50/50 p-6 rounded-3xl border border-yellow-200 shadow-sm">
                        <h3 class="text-xl font-display font-bold text-yellow-800 mb-4">Create Assignment (Reviews/Projects)</h3>
                        <form id="createAssignmentForm" class="space-y-4">
                            <input type="hidden" name="action" value="create_assignment">
                            <select name="program_id" id="assignmentProgramSelect" required class="w-full px-4 py-3 border border-yellow-300 rounded-xl outline-none bg-white"></select>
                            <input type="text" name="title" required placeholder="Assignment Title" class="w-full px-4 py-3 border border-yellow-300 rounded-xl outline-none bg-white">
                            <select name="assignment_type" required class="w-full px-4 py-3 border border-yellow-300 rounded-xl outline-none bg-white">
                                <option value="Sermon_Review">Sermon Review</option>
                                <option value="Book_Review">Book Review</option>
                                <option value="Group_Project">Group Project (PowerPoint)</option>
                            </select>
                            <input type="datetime-local" name="deadline" required class="w-full px-4 py-3 border border-yellow-300 rounded-xl outline-none bg-white text-sm text-gray-500">
                            <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-8 py-3 rounded-xl font-bold w-full transition-all">Deploy Assignment</button>
                        </form>
                    </div>

                    <div class="bg-red-50/50 p-6 rounded-3xl border border-red-100 shadow-sm">
                        <h3 class="text-xl font-display font-bold text-hodRed mb-4">Add Exam Question</h3>
                        <form id="addQuestionForm" class="space-y-3">
                            <input type="hidden" name="action" value="add_exam_question">
                            <select name="course_id" id="questionCourseSelect" required class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white"></select>
                            <textarea name="question" required rows="2" placeholder="Question..." class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white resize-none"></textarea>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" name="option_a" required placeholder="Opt A *" class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white">
                                <input type="text" name="option_b" required placeholder="Opt B *" class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white">
                                <input type="text" name="option_c" required placeholder="Opt C *" class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white">
                                <input type="text" name="option_d" placeholder="Opt D" class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white">
                            </div>
                            <select name="correct_option" required class="w-full px-4 py-2 border border-red-200 rounded-xl outline-none bg-white font-bold text-hodRed">
                                <option value="A">Answer: A</option><option value="B">Answer: B</option><option value="C">Answer: C</option><option value="D">Answer: D</option>
                            </select>
                            <button type="submit" class="bg-white border-2 border-hodRed text-hodRed hover:bg-hodRed hover:text-white py-2 rounded-xl font-bold w-full transition-all">Save Question</button>
                        </form>
                    </div>

                    <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm col-span-full">
                        <h3 class="text-xl font-display font-bold text-gray-900 mb-4 flex items-center gap-2">
                            <svg class="w-6 h-6 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                            Exam Publisher Engine
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-gray-600">
                                <thead class="bg-gray-50 text-gray-500 font-bold uppercase text-[10px] border-b border-gray-100">
                                    <tr><th class="px-6 py-3">Course Name</th><th class="px-6 py-3 text-center">Status</th><th class="px-6 py-3 text-right">Action</th></tr>
                                </thead>
                                <tbody id="hodExamPublisherTable" class="divide-y divide-gray-50"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-content-student-dash" class="hidden space-y-6 animate-fade-in-up">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-gray-50/50 rounded-3xl border border-gray-100 p-6 flex flex-col h-[600px]">
                        <h3 class="text-xl font-display font-bold text-gray-900 mb-4 border-b border-gray-200 pb-2">Cohort Noticeboard</h3>
                        <div id="forumMessagesArea" class="flex-1 overflow-y-auto space-y-4 mb-4 custom-scrollbar pr-2">
                            </div>
                        <form id="postForumForm" class="flex gap-2 mt-auto">
                            <input type="hidden" name="action" value="post_forum_message">
                            <input type="hidden" name="batch_id" id="forumBatchId">
                            <input type="text" name="message" required placeholder="Type a message or response..." class="flex-1 px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white">
                            <button type="submit" class="bg-hodBlue text-white px-6 py-3 rounded-xl font-bold shadow-md hover:bg-blue-900 transition-all">Send</button>
                        </form>
                    </div>
                    <div class="bg-white rounded-3xl border border-gray-100 p-6 shadow-sm h-[600px] overflow-y-auto">
                        <h3 class="text-lg font-display font-bold text-gray-900 mb-4 border-b border-gray-100 pb-2">My Timetable</h3>
                        <div id="studentEventsList" class="space-y-4"></div>
                    </div>
                </div>
            </div>

            <div id="tab-content-student-study" class="hidden space-y-8 animate-fade-in-up">
                <div>
                    <h3 class="text-xl font-display font-bold text-gray-900 mb-4">Course Materials</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="studentCoursesGrid"></div>
                </div>
                <div class="pt-6 border-t border-gray-100">
                    <h3 class="text-xl font-display font-bold text-gray-900 mb-4">Pending Assignments & Projects</h3>
                    <div class="space-y-4" id="studentAssignmentsList"></div>
                </div>
            </div>

            <div id="tab-content-student-exam" class="hidden space-y-6 animate-fade-in-up">
                <div id="examSelectionState">
                    <h3 class="text-xl font-display font-bold text-gray-900 mb-6">Available Examinations</h3>
                    <div class="grid grid-cols-1 gap-4" id="examAvailableList"></div>
                </div>

                <div id="activeExamState" class="hidden relative bg-gray-50/50 rounded-3xl p-6 md:p-10 border border-gray-100">
                    <div class="sticky top-20 bg-hodRed text-white p-5 rounded-2xl shadow-xl flex justify-between items-center z-40 mb-8 border border-red-500 backdrop-blur-lg">
                        <div>
                            <h3 class="font-bold text-lg flex items-center gap-2"><svg class="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Active Examination</h3>
                            <p class="text-xs text-red-200 mt-1 font-medium">Do not refresh. Progress will be lost.</p>
                        </div>
                        <div class="bg-black/20 px-6 py-2 rounded-xl border border-white/10 text-3xl font-mono font-bold tracking-wider" id="examTimerDisplay">00:00</div>
                    </div>
                    <form id="activeExamForm" class="space-y-10 max-w-4xl mx-auto">
                        <input type="hidden" name="action" value="submit_exam">
                        <input type="hidden" name="course_id" id="activeExamCourseId">
                        <div id="examQuestionsContainer" class="space-y-8"></div>
                        <div class="pt-8 border-t border-gray-200 flex justify-end">
                            <button type="submit" id="submitExamBtn" class="bg-hodBlue hover:bg-[#152750] text-white px-10 py-4 rounded-xl font-bold text-lg transition-all shadow-xl">Submit Answers</button>
                        </div>
                    </form>
                </div>

                <div id="examResultsState" class="hidden text-center py-10 px-4 max-w-3xl mx-auto">
                    <div id="resultIcon" class="mx-auto flex items-center justify-center h-28 w-28 rounded-full mb-6 shadow-inner"></div>
                    <h2 id="resultTitle" class="text-4xl font-display font-bold mb-3 tracking-tight"></h2>
                    <p id="resultMessage" class="text-xl text-gray-600 mb-10 font-medium"></p>
                    <div class="bg-white rounded-3xl p-8 text-left border border-gray-100 shadow-sm">
                        <h4 class="font-bold text-gray-900 mb-6 border-b border-gray-100 pb-3">Detailed Breakdown</h4>
                        <ul id="resultBreakdown" class="space-y-4"></ul>
                    </div>
                    <div class="mt-8"><button onclick="location.reload()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-8 py-3 rounded-xl transition-colors">Return</button></div>
                </div>
            </div>

            <div id="tab-content-student-result" class="hidden space-y-6 animate-fade-in-up">
                <div class="flex justify-end mb-4 no-print">
                    <button onclick="window.print()" class="bg-gray-800 text-white px-6 py-2 rounded-lg font-bold shadow-md hover:bg-black transition-all flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg> Print Transcript
                    </button>
                </div>
                
                <div id="printableResultSlip" class="bg-white p-10 md:p-16 rounded-3xl border-2 border-gray-200 shadow-sm max-w-4xl mx-auto relative overflow-hidden">
                    <div class="absolute inset-0 flex items-center justify-center opacity-[0.03] pointer-events-none">
                        <svg class="w-[500px] h-[500px]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                    </div>
                    
                    <div class="text-center border-b-4 border-hodBlue pb-6 mb-8 relative z-10">
                        <h1 class="text-4xl font-display font-black text-gray-900 uppercase tracking-widest">HOD Academy</h1>
                        <p class="text-lg text-gray-600 font-medium mt-1">Household of David, Lekki Centre</p>
                        <h2 class="text-2xl font-bold text-hodBlue mt-4 uppercase tracking-wider">Official Transcript & Result Slip</h2>
                    </div>

                    <div class="grid grid-cols-2 gap-6 mb-8 text-sm relative z-10">
                        <div>
                            <p class="text-gray-500 font-bold uppercase tracking-wider mb-1">Student Name</p>
                            <p class="text-xl font-bold text-gray-900" id="slipStudentName"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-gray-500 font-bold uppercase tracking-wider mb-1">Program & Batch</p>
                            <p class="text-lg font-bold text-gray-900" id="slipProgramBatch"></p>
                        </div>
                    </div>

                    <div class="mb-8 relative z-10">
                        <table class="w-full text-left text-sm border-collapse border border-gray-200">
                            <thead class="bg-gray-100 font-bold text-gray-700 uppercase tracking-wider">
                                <tr>
                                    <th class="p-4 border border-gray-200">Assessment Metric</th>
                                    <th class="p-4 border border-gray-200 text-center">Score / Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-800" id="slipMetricsTable">
                                </tbody>
                        </table>
                    </div>

                    <div class="mt-16 pt-8 border-t-2 border-gray-100 flex justify-between items-end relative z-10">
                        <div>
                            <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">Date Issued</p>
                            <p class="font-bold text-gray-800"><?php echo date('F d, Y'); ?></p>
                        </div>
                        <div class="text-center">
                            <p class="font-signature text-3xl text-hodBlue mb-2 transform -rotate-6">Bolanle Uzo-Peters</p>
                            <div class="w-48 h-px bg-gray-400 mx-auto mb-2"></div>
                            <p class="font-bold text-gray-900">Minister Bolanle Uzo-Peters</p>
                            <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">Head of Department, HOD Academy</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="submitAssignmentModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-xl font-bold text-gray-900" id="subModalTitle">Submit Assignment</h3>
            <button onclick="closeModal('submitAssignmentModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="submitAssignmentForm" enctype="multipart/form-data" class="p-6 md:p-8 space-y-6">
            <input type="hidden" name="action" value="submit_assignment">
            <input type="hidden" name="assignment_id" id="subModalId">
            
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Type your response</label>
                <textarea name="submission_text" rows="5" placeholder="Type your review or text here..." class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue resize-none"></textarea>
            </div>
            
            <div class="relative flex py-3 items-center">
                <div class="flex-grow border-t border-gray-200"></div>
                <span class="flex-shrink-0 mx-4 text-gray-400 text-xs font-bold uppercase">AND / OR</span>
                <div class="flex-grow border-t border-gray-200"></div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Upload File (PPT, DOC, PDF)</label>
                <input type="file" name="submission_file" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none bg-gray-50">
            </div>
            
            <button type="submit" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-6 py-4 rounded-xl font-bold transition-all shadow-md">Secure Submission</button>
        </form>
    </div>
</div>

<div id="panelInterviewModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-blue-50">
            <h3 class="text-xl font-bold text-hodBlue">Panel Interview Rubric</h3>
            <button onclick="closeModal('panelInterviewModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="submitInterviewForm" class="p-6 md:p-8 space-y-6">
            <input type="hidden" name="action" value="submit_interview_score">
            <input type="hidden" name="enrollment_id" id="interviewEnrollmentId">
            
            <p class="text-sm text-gray-600 font-medium mb-4">Evaluating: <span id="interviewStudentName" class="font-bold text-gray-900"></span></p>

            <div class="space-y-4">
                <div class="flex justify-between items-center p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <label class="text-sm font-bold text-gray-700">Doctrinal Knowledge (out of 10)</label>
                    <input type="number" name="doctrinal_score" min="0" max="10" required class="w-20 px-3 py-2 text-center rounded-lg border border-gray-300 outline-none focus:border-hodBlue">
                </div>
                <div class="flex justify-between items-center p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <label class="text-sm font-bold text-gray-700">Zeal for Service (out of 10)</label>
                    <input type="number" name="zeal_score" min="0" max="10" required class="w-20 px-3 py-2 text-center rounded-lg border border-gray-300 outline-none focus:border-hodBlue">
                </div>
                <div class="flex justify-between items-center p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <label class="text-sm font-bold text-gray-700">Character & Comportment (out of 10)</label>
                    <input type="number" name="character_score" min="0" max="10" required class="w-20 px-3 py-2 text-center rounded-lg border border-gray-300 outline-none focus:border-hodBlue">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Panel Comments</label>
                <textarea name="comments" rows="2" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue resize-none"></textarea>
            </div>
            
            <button type="submit" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-6 py-4 rounded-xl font-bold transition-all shadow-md">Save Official Score</button>
        </form>
    </div>
</div>

<div id="approveStudentModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-900">Assign to Cohort</h3>
            <button onclick="closeModal('approveStudentModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="approveStudentForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="approve_application">
            <input type="hidden" name="application_id" id="approveAppId">
            
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Select Target Batch *</label>
                <select name="batch_id" id="approveBatchSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-white font-medium text-gray-700">
                    <option value="">Loading batches...</option>
                </select>
            </div>
            
            <button type="submit" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all">Approve & Enroll Student</button>
        </form>
    </div>
</div>

<script>
    const API_URL = '../../api/academy_api.php';
    let isAdmin = false;
    let timerInterval = null;
    let activeBatchId = null;
    
    
    // --- Conditional Form Logic ---
    function toggleAnniversary(status) {
        if(status === 'Married') {
            $('#anniversaryField').removeClass('hidden').addClass('animate-fade-in-up');
        } else {
            $('#anniversaryField').addClass('hidden').removeClass('animate-fade-in-up');
            $('input[name="wedding_anniversary"]').val(''); // Clear it if they change their mind
        }
    }
    // --- Core UI Toggles ---
    function switchTab(tabId) {
        $('[id^="tab-btn-"]').removeClass('border-hodBlue text-hodBlue').addClass('border-transparent text-gray-500 hover:text-gray-700');
        $(`#tab-btn-${tabId}`).removeClass('border-transparent text-gray-500 hover:text-gray-700').addClass('border-hodBlue text-hodBlue');
        $('[id^="tab-content-"]').addClass('hidden').removeClass('animate-fade-in-up');
        setTimeout(() => { $(`#tab-content-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up'); }, 10);
    }

    function toggleUploadType(type) {
        const area = document.getElementById('uploadInputArea');
        if (type === 'Document') {
            area.innerHTML = `<input type="file" name="file_upload" accept=".pdf,.doc,.docx" required class="w-full px-4 py-2 border border-blue-200 rounded-xl outline-none bg-white">`;
        } else {
            area.innerHTML = `<input type="url" name="file_url" required placeholder="URL Link..." class="w-full px-4 py-3 border border-blue-200 rounded-xl outline-none bg-white">`;
        }
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        const inner = modal.children[0];
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); inner.classList.remove('scale-95'); }, 10);
    }
    function closeModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        const inner = modal.children[0];
        modal.classList.add('opacity-0'); inner.classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); const f = modal.querySelector('form'); if(f) f.reset(); }, 300);
    }

    function handleAjaxError(xhr) {
        $('#loadingOverlay').addClass('hidden');
        console.error("AJAX Error:", xhr.responseText);
        Toastify({ text: "Server Connection Error.", style: { background: "#EF4444", borderRadius: "10px" } }).showToast();
    }

    // --- Lock Sidebar Script ---
    function lockSidebarForStudents() {
        // If not admin, hide everything in the sidebar except Dashboard, Academy, and Logout.
        if(!isAdmin) {
            $('.sidebar-nav-item').each(function() {
                let text = $(this).text().trim().toLowerCase();
                if(text !== 'dashboard' && text !== 'hod academy' && text !== 'logout') {
                    $(this).hide();
                }
            });
        }
    }

    // --- Submissions & Grading ---
    function openSubmitModal(id, title) {
        $('#subModalId').val(id);
        $('#subModalTitle').text(title);
        openModal('submitAssignmentModal');
    }

    function gradeSubmission(subId) {
        let score = prompt("Enter Grade out of 100:");
        if (score !== null && score !== "") {
            $.post(API_URL, { action: 'grade_assignment', submission_id: subId, score: score }, function(res) {
                Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
                if(res.status === 'success') loadAcademyData();
            }, 'json').fail(handleAjaxError);
        }
    }

    // --- Approve Student Action ---
    function approveStudent(appId) {
        $('#approveAppId').val(appId);
        openModal('approveStudentModal');
    }

    function graduateStudent(id, batchId) {
        if(!confirm("Are you sure you want to graduate this student to 'Worker' status?")) return;
        $.post(API_URL, { action: 'graduate_student', student_id: id, batch_id: batchId }, function(res) {
            Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
            if(res.status === 'success') loadBatchMeritList(batchId);
        }, 'json').fail(handleAjaxError);
    }

    function toggleExamPublish(courseId, currentStatus) {
        let newStatus = currentStatus == 1 ? 0 : 1;
        if(!confirm(newStatus == 1 ? "Publish exam?" : "Retract exam?")) return;
        $.post(API_URL, { action: 'publish_exam', course_id: courseId, publish_status: newStatus }, function(res) {
            Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
            if(res.status === 'success') loadAcademyData(); 
        }, 'json').fail(handleAjaxError);
    }
    
    function toggleCoursePublish(courseId, currentStatus) {
        if(!confirm(currentStatus == 1 ? "Publish this course to student portals?" : "Retract and hide this course?")) return;
        $.post(API_URL, { action: 'publish_course', course_id: courseId, publish_status: currentStatus }, function(res) {
            Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
            if(res.status === 'success') loadAcademyData(); 
        }, 'json').fail(handleAjaxError);
    }
    
    function openInterviewModal(enrollmentId, studentName) {
        $('#interviewEnrollmentId').val(enrollmentId);
        $('#interviewStudentName').text(studentName);
        openModal('panelInterviewModal');
    }

    // --- Master Data Loader ---
    function loadAcademyData() {
        $.ajax({
            url: API_URL,
            type: 'GET',
            data: { action: 'fetch_dashboard' },
            dataType: 'json',
            success: function(res) {
                $('#loadingOverlay').addClass('hidden');
                
                if(res.status === 'success') {
                    isAdmin = res.is_admin;
                    lockSidebarForStudents(); // Execute strict sidebar lock

                    if (isAdmin) {
                        $('#academySubtitle').text('Manage curriculum, graduation merit, and records.');
                        $('#academyNavigation, #academyContentArea').removeClass('hidden');
                        $('#tab-btn-admin-merit, #tab-btn-admin-grading, #tab-btn-admin-setup, #tab-btn-admin-curriculum').removeClass('hidden');
                        switchTab('admin-merit');

                        // Populate Setup Dropdowns
                        let pOpts = '<option value="" disabled selected>Select Program...</option>';
                        if(res.data.programs) res.data.programs.forEach(p => pOpts += `<option value="${p.id}">${p.name}</option>`);
                        $('#setupProgramSelect, #courseProgramSelect, #assignmentProgramSelect').html(pOpts);

                        let cOpts = '<option value="" disabled selected>Select Course...</option>';
                        if(res.data.courses) res.data.courses.forEach(c => cOpts += `<option value="${c.id}">${c.name}</option>`);
                        $('#questionCourseSelect, #materialCourseSelect').html(cOpts); 

                        let bOpts = '<option value="" disabled selected>Select Active Batch...</option>';
                        if(res.data.active_batches) res.data.active_batches.forEach(b => bOpts += `<option value="${b.id}">[ID: ${b.id}] ${b.batch_name}</option>`);
                        $('#enrollBatchSelect, #adminBatchSelect, #approveBatchSelect').html(bOpts);
                        // Fetch and populate Course Directors (Lecturers)
                        $.post(API_URL, { action: 'fetch_setup_data' }, function(setupRes) {
                            if(setupRes.status === 'success' && setupRes.instructors) {
                                let insOpts = '<option value="" disabled selected>Assign Director...</option>';
                                setupRes.instructors.forEach(i => insOpts += `<option value="${i.id}">${i.first_name} ${i.last_name}</option>`);
                                $('#courseInstructorSelect').html(insOpts);
                            }
                        }, 'json');

                        // Populate Pending Applications
                        let appHtml = '';
                        if(res.data.pending_applications.length === 0) appHtml = '<tr><td colspan="4" class="text-center py-6 text-gray-500">No pending applications.</td></tr>';
                        else {
                            res.data.pending_applications.forEach(a => {
                                appHtml += `<tr><td class="px-4 py-3 font-bold">${a.full_name}</td><td class="px-4 py-3">${a.phone_number}</td><td class="px-4 py-3 text-xs italic text-gray-500">See ID in dropdown above</td><td class="px-4 py-3"><button onclick="approveStudent(${a.id})" class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded font-bold">Approve & Enroll</button></td></tr>`;
                            });
                        }
                        $('#pendingAppsTableBody').html(appHtml);

                        // Populate Exam Publisher
                        let pHtml = '';
                        if(res.data.courses.length === 0) pHtml = '<tr><td colspan="3" class="text-center py-8 text-gray-500">No courses.</td></tr>';
                        else {
                            res.data.courses.forEach(c => {
                                let isCoursePub = parseInt(c.is_course_published) === 1;
                                let isExamPub = parseInt(c.is_exam_published) === 1;
                                
                                let cBadge = isCoursePub ? `<span class="text-green-600 font-bold text-xs uppercase">✓ Course</span>` : `<span class="text-gray-400 font-bold text-xs uppercase">🔒 Course</span>`;
                                let eBadge = isExamPub ? `<span class="text-green-600 font-bold text-xs uppercase">✓ Exam</span>` : `<span class="text-gray-400 font-bold text-xs uppercase">🔒 Exam</span>`;
                                
                                let courseBtn = isCoursePub 
                                    ? `<button onclick="toggleCoursePublish(${c.id}, 0)" class="text-xs bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded font-bold border border-red-100 transition-all">Hide Course</button>`
                                    : `<button onclick="toggleCoursePublish(${c.id}, 1)" class="text-xs bg-green-50 text-green-700 hover:bg-green-600 hover:text-white px-3 py-1.5 rounded font-bold border border-green-100 transition-all">Push Course</button>`;

                                let examBtn = isExamPub
                                    ? `<button onclick="toggleExamPublish(${c.id}, 0)" class="text-xs bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded font-bold border border-red-100 transition-all">Hide Exam</button>`
                                    : `<button onclick="toggleExamPublish(${c.id}, 1)" class="text-xs bg-hodBlue text-white hover:bg-[#152750] px-3 py-1.5 rounded font-bold transition-all">Push Exam</button>`;

                                let director = c.instr_fname ? `<br><span class="text-[10px] text-gray-500 uppercase tracking-wider">Dir: ${c.instr_fname} ${c.instr_lname}</span>` : '';

                                pHtml += `
                                <tr class="hover:bg-gray-50/50 transition-colors border-b border-gray-50 last:border-0">
                                    <td class="px-6 py-4">
                                        <span class="font-bold text-gray-900 block">${c.name}</span>
                                        <span class="text-xs text-hodBlue font-medium">${c.program_name || 'Unassigned Program'}</span>
                                        ${director}
                                    </td>
                                    <td class="px-6 py-4 text-center space-x-2">${cBadge} ${eBadge}</td>
                                    <td class="px-6 py-4 text-right space-x-2 flex justify-end">${courseBtn} ${examBtn}</td>
                                </tr>`;
                            });
                        }
                        $('#hodExamPublisherTable').html(pHtml);

                        // Populate Grading Room
                        let gHtml = '';
                        if(res.data.pending_grading.length === 0) gHtml = '<div class="col-span-full py-10 text-center text-gray-500">No pending submissions to grade.</div>';
                        else {
                            res.data.pending_grading.forEach(g => {
                                let content = g.file_url ? `<a href="${g.file_url}" target="_blank" class="text-hodBlue font-bold text-sm hover:underline flex items-center gap-1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> View Attached File</a>` : `<p class="text-sm text-gray-600 mt-2 p-3 bg-white rounded-lg border border-gray-100 max-h-24 overflow-y-auto">${g.submission_text}</p>`;
                                gHtml += `
                                <div class="bg-gray-50 p-5 rounded-2xl border border-gray-200">
                                    <div class="flex justify-between items-start mb-2">
                                        <div><h4 class="font-bold text-gray-900">${g.title}</h4><p class="text-xs text-gray-500">By: ${g.first_name} ${g.last_name}</p></div>
                                        <button onclick="gradeSubmission(${g.id})" class="bg-hodBlue text-white px-4 py-1.5 rounded-lg text-xs font-bold">Input Grade</button>
                                    </div>
                                    ${content}
                                </div>`;
                            });
                        }
                        $('#gradingRoomContainer').html(gHtml);

                    } else {
                        // ==========================================
                        // STUDENT VIEW ROUTING
                        // ==========================================
                        let state = res.data.status;
                        
                        if(state === 'Not_Applied') {
                            $('#state-not-applied').removeClass('hidden');
                        } 
                        else if(state === 'Application_Pending') {
                            $('#state-application-pending').removeClass('hidden');
                        } 
                        else if(state === 'Enrolled' || state === 'Graduated') {
                            $('#academySubtitle').text('Your digital classroom and examination center.');
                            $('#academyNavigation, #academyContentArea').removeClass('hidden');
                            
                            if (state === 'Graduated') {
                                $('#tab-btn-student-result').removeClass('hidden');
                                switchTab('student-result');
                                // (Result slip logic would fetch specific finalized grades here in a full DB expansion)
                                $('#slipStudentName').text("Graduate Record"); 
                                $('#slipProgramBatch').text("Status: Passed & Certified");
                            } else {
                                $('#tab-btn-student-dash, #tab-btn-student-study, #tab-btn-student-exam').removeClass('hidden');
                                switchTab('student-dash');
                                
                                activeBatchId = res.data.enrollment.batch_id;
                                $('#forumBatchId').val(activeBatchId);
                                loadForum(); // Start Forum sync

                                // 1. Timetable (Events)
                                let evtHtml = '';
                                if(res.data.events.length > 0) {
                                    res.data.events.forEach(e => {
                                        const dt = new Date(e.event_date).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });
                                        evtHtml += `<div class="p-4 bg-gray-50 border border-gray-100 rounded-xl"><h4 class="font-bold text-gray-900">${e.title}</h4><p class="text-xs text-hodBlue font-bold mt-1">${dt}</p></div>`;
                                    });
                                } else { evtHtml = '<p class="text-gray-500 text-sm">No scheduled events.</p>'; }
                                $('#studentEventsList').html(evtHtml);

                                // 2. Study Room (Materials & Exams)
                                let studyHtml = ''; let examHtml = '';
                                res.data.courses.forEach(c => {
                                    let matHtml = '';
                                    if(c.materials.length > 0) {
                                        c.materials.forEach(m => {
                                            let icon = m.material_type === 'Link' ? 'M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z M21 12a9 9 0 11-18 0 9 9 0 0118 0z' : 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z';
                                            matHtml += `<a href="${m.file_url}" target="_blank" class="flex items-center gap-2 p-2 hover:bg-gray-50 text-sm font-semibold text-hodBlue rounded-lg transition-all"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${icon}"></path></svg> ${m.title}</a>`;
                                        });
                                    } else { matHtml = '<p class="text-xs text-gray-400 italic">No materials uploaded yet.</p>'; }
                                    
                                    studyHtml += `<div class="bg-gray-50 border border-gray-200 rounded-2xl p-6"><h4 class="font-bold text-gray-900 text-lg border-b border-gray-200 pb-2 mb-3">${c.name}</h4><div class="space-y-1">${matHtml}</div></div>`;

                                    let exBtn = c.is_exam_published == 0 ? `<button disabled class="bg-gray-100 text-gray-500 px-4 py-2 rounded-xl text-sm font-bold w-full cursor-not-allowed">Locked by HOD</button>` : `<button onclick="startExam(${c.id})" class="bg-hodBlue text-white px-4 py-2 rounded-xl text-sm font-bold w-full">Start Exam</button>`;
                                    if (c.exam_status && c.exam_status.status === 'Passed') exBtn = `<button disabled class="bg-green-50 text-green-700 border border-green-200 px-4 py-2 rounded-xl text-sm font-bold w-full">Passed (${c.exam_status.score}%)</button>`;
                                    else if (c.exam_status && c.exam_status.status === 'Failed') exBtn = `<button disabled class="bg-red-50 text-red-700 border border-red-200 px-4 py-2 rounded-xl text-sm font-bold w-full">Failed (${c.exam_status.score}%)</button>`;
                                    
                                    examHtml += `<div class="flex justify-between items-center p-5 bg-gray-50 border border-gray-200 rounded-2xl"><div><h4 class="font-bold text-gray-900">${c.name}</h4><p class="text-xs text-gray-500">${c.timer_minutes} Mins</p></div><div class="w-32">${exBtn}</div></div>`;
                                });
                                $('#studentCoursesGrid').html(studyHtml); $('#examAvailableList').html(examHtml);

                                // 3. Assignments
                                let assHtml = '';
                                if(res.data.assignments.length > 0) {
                                    res.data.assignments.forEach(a => {
                                        let act = a.my_score !== null ? `<span class="text-green-600 font-bold bg-green-50 px-3 py-1 rounded">Graded: ${a.my_score}/100</span>` : `<button onclick="openSubmitModal(${a.id}, '${a.title.replace(/'/g, "\\'")}')" class="bg-hodBlue text-white px-4 py-2 rounded-lg text-xs font-bold shadow-sm">Submit Work</button>`;
                                        assHtml += `<div class="flex justify-between items-center p-5 bg-white border border-gray-200 rounded-2xl shadow-sm"><div><h4 class="font-bold text-gray-900">${a.title}</h4><p class="text-xs text-hodRed font-bold mt-1">Due: ${new Date(a.deadline).toLocaleString()}</p></div><div>${act}</div></div>`;
                                    });
                                } else { assHtml = '<p class="text-gray-500">No pending assignments.</p>'; }
                                $('#studentAssignmentsList').html(assHtml);
                            }
                        }
                    }
                }
            },
            error: handleAjaxError
        });
    }

    // --- Admin: Batch Merit & Final Panel Loader ---
    function loadBatchMeritList(batchId) {
        if(!batchId) return;
        $('#meritListArea').removeClass('hidden');
        $('#adminMeritTableBody').html('<tr><td colspan="6" class="text-center py-8 text-hodBlue animate-pulse">Calculating Merit Engine...</td></tr>');

        $.post(API_URL, { action: 'fetch_batch_merit_list', batch_id: batchId }, function(res) {
            if(res.status === 'success') {
                let html = '';
                if(res.students.length === 0) html = '<tr><td colspan="6" class="text-center py-8 text-gray-500">No students.</td></tr>';
                else {
                    res.students.forEach((s, index) => {
                        let attCount = s.attended_events || 0;
                        let attColor = res.total_events > 0 && (attCount/res.total_events >= 0.7) ? 'text-green-600' : 'text-gray-500';
                        let examScore = s.avg_exam_score !== null ? parseFloat(s.avg_exam_score).toFixed(1) + '%' : 'N/A';
                        
                        let panelScore = s.interview_score !== null ? `<span class="font-bold text-hodBlue">${s.interview_score}/30</span>` : `<button onclick="openInterviewModal(${s.enrollment_id}, '${s.first_name} ${s.last_name}')" class="text-[10px] bg-purple-50 text-purple-700 px-2 py-1 rounded font-bold border border-purple-200">Score Panel</button>`;

                        let actBtn = s.graduation_status === 'Graduated' ? `<span class="text-xs font-bold text-gray-400">Graduated ✓</span>` : `<button onclick="graduateStudent(${s.student_id}, ${batchId})" class="text-xs bg-hodBlue text-white px-3 py-1.5 rounded font-bold shadow-sm">Graduate</button>`;

                        html += `<tr class="border-b border-gray-50"><td class="px-6 py-4 font-black text-gray-400">#${index + 1}</td><td class="px-6 py-4 font-bold text-gray-900">${s.first_name} ${s.last_name}</td><td class="px-6 py-4 text-center font-bold ${attColor}">${attCount}/${res.total_events}</td><td class="px-6 py-4 text-center font-bold">${examScore}</td><td class="px-6 py-4 text-center">${panelScore}</td><td class="px-6 py-4 text-right">${actBtn}</td></tr>`;
                    });
                }
                $('#adminMeritTableBody').html(html);
            }
        }, 'json').fail(handleAjaxError);
    }

    // --- Forum Functions ---
    function loadForum() {
        if(!activeBatchId) return;
        $.post(API_URL, { action: 'fetch_forum', batch_id: activeBatchId }, function(res) {
            if(res.status === 'success') {
                let html = '';
                res.messages.forEach(m => {
                    let isLec = m.spiritual_status === 'Worker' ? '<span class="bg-hodBlue text-white text-[10px] px-2 py-0.5 rounded ml-2">Admin</span>' : '';
                    html += `<div class="bg-white p-3 rounded-xl shadow-sm border border-gray-100"><div class="flex justify-between items-center mb-1"><span class="font-bold text-sm text-gray-900">${m.first_name} ${m.last_name} ${isLec}</span><span class="text-[10px] text-gray-400">${new Date(m.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}</span></div><p class="text-sm text-gray-700">${m.message}</p></div>`;
                });
                $('#forumMessagesArea').html(html);
            }
        }, 'json');
    }

    $('#postForumForm').on('submit', function(e) {
        e.preventDefault();
        $.post(API_URL, $(this).serialize(), function(res) {
            if(res.status === 'success') { $('#postForumForm')[0].reset(); loadForum(); }
        }, 'json');
    });

    // --- Master Initializer ---
    $(document).ready(function() {
        loadAcademyData();

        function bindAjaxForm(formId) {
            $(`#${formId}`).on('submit', function(e) {
                e.preventDefault();
                const btn = $(this).find('button[type="submit"]');
                const orig = btn.html(); btn.prop('disabled', true).html('Processing...');
                
                let formData = new FormData(this);
                $.ajax({
                    url: API_URL, type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
                    success: function(res) {
                        btn.prop('disabled', false).html(orig);
                        Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
                        if(res.status === 'success') { $(`#${formId}`)[0].reset(); loadAcademyData(); if(formId === 'submitAssignmentModal' || formId === 'panelInterviewModal') closeModal(formId); }
                    },
                    error: function() { btn.prop('disabled', false).html(orig); handleAjaxError(); }
                });
            });
        }

        bindAjaxForm('academyApplicationForm');
        bindAjaxForm('createProgramForm');
        bindAjaxForm('createCourseForm');
        bindAjaxForm('createBatchForm');
        bindAjaxForm('addQuestionForm');
        bindAjaxForm('createAssignmentForm');
        bindAjaxForm('addMaterialForm');
        bindAjaxForm('submitAssignmentForm');
        bindAjaxForm('submitInterviewForm');
        
        // Handle the new Subtle Approval Modal
        $('#approveStudentForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const orig = btn.html(); 
            btn.prop('disabled', true).html('Enrolling...');
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).html(orig);
                Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "500" } }).showToast();
                
                if(res.status === 'success') {
                    closeModal('approveStudentModal');
                    $('#approveStudentForm')[0].reset();
                    loadAcademyData(); // Refresh the tables to remove them from pending
                }
            }, 'json').fail(function() {
                btn.prop('disabled', false).html(orig);
                handleAjaxError();
            });
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>