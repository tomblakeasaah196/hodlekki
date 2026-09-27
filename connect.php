<?php
// /connect.php
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome Home | Household of David Lekki Centre</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@600;800;900&display=swap" rel="stylesheet">
    
    <!-- Geoapify Autocomplete API -->
    <link rel="stylesheet" href="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/styles/minimal.css">
    <script src="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/dist/index.min.js"></script>

    <style>
        /* Custom Geoapify styling to match your form */
        .geoapify-autocomplete-input {
            width: 100%;
            padding: 0.875rem 1rem !important; /* matches px-4 py-3.5 */
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
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            position: absolute;
            z-index: 9999;
        }
    </style>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Montserrat', 'sans-serif'] },
                    colors: { hodBlue: '#0A0E17', hodRed: '#D11920' },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                    },
                    keyframes: {
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }
                    }
                }
            }
        }
    </script>
    
    <style>
        body { color: #1F2937; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #D11920; }
        
        /* Warm Light Glass-morphism */
        .glass-panel {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.03);
        }
        
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Wizard field errors (set by showError) */
        .field-invalid { border-color: #D11920 !important; background-color: #FEF2F2 !important; box-shadow: 0 0 0 3px rgba(209, 25, 32, 0.15) !important; }
        .field-error-msg { margin-top: 0.375rem; font-size: 0.75rem; font-weight: 700; color: #D11920; line-height: 1.35; }
    </style>
</head>
<body class="bg-gray-50 antialiased min-h-screen relative overflow-x-hidden selection:bg-hodRed selection:text-white">

    <!-- Strictly Branded Welcoming Background -->
    <div class="fixed inset-0 z-[-1] overflow-hidden bg-white">
        <!-- Friendly fellowship/connection image -->
        <img src="/assets/images/hod_lekki.jpeg" 
             alt="People connecting in fellowship" 
             class="w-full h-full object-cover opacity-60">
        
        <div class="absolute inset-0 bg-gradient-to-b from-white/70 via-white/50 to-white/95"></div>
        
        <!-- Brand-specific ambient glows -->
        <div class="absolute top-0 right-1/4 w-[600px] h-[600px] bg-hodRed/10 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-0 left-1/4 w-[500px] h-[500px] bg-hodBlue/10 rounded-full blur-[150px]"></div>
    </div>

    <!-- MAIN LANDING VIEW -->
    <div id="landingView" class="relative z-10 flex flex-col items-center justify-center min-h-screen p-6 md:p-10 fade-in">
        
        <div class="max-w-5xl w-full mx-auto space-y-12 pt-10">
            <!-- Hero Welcome -->
            <div class="text-center space-y-6">
                <img src="/assets/images/hod_logo.svg" alt="HOD Logo" class="h-20 mx-auto drop-shadow-sm mb-4" onerror="this.onerror=null; this.src='https://placehold.co/200x60/0A0E17/FFF?text=HOD+Lekki'">
                <h1 class="text-5xl md:text-7xl font-heading font-black text-gray-900 tracking-tight drop-shadow-sm">Welcome Home.</h1>
                <p class="text-lg md:text-xl text-gray-600 font-medium max-w-2xl mx-auto leading-relaxed">
                    Household of David is a global ministry led by <span class="font-bold text-hodRed">Pastor Sola Osunmakinde</span>. Our Resident Pastor is <span class="font-bold text-hodRed">Pastor Ebele Uzo-Peters</span>. Whether you're just passing through or looking for a family, you belong here.
                </p>
            </div>
            
            <!-- Identity Cards (Vision, Mission, Values) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6">
                <div class="glass-panel p-8 rounded-3xl hover:-translate-y-1 transition-transform duration-300">
                    <div class="w-12 h-12 bg-hodRed/10 rounded-full flex items-center justify-center mb-5 border border-hodRed/20">
                        <svg class="w-6 h-6 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                    </div>
                    <h3 class="text-xl font-heading font-black text-gray-900 mb-3 tracking-tight">Our Vision</h3>
                    <p class="text-sm text-gray-600 font-medium leading-relaxed">To raise a people after God’s heart in every nation of the earth.</p>
                </div>
                <div class="glass-panel p-8 rounded-3xl hover:-translate-y-1 transition-transform duration-300">
                    <div class="w-12 h-12 bg-hodBlue/10 rounded-full flex items-center justify-center mb-5 border border-hodBlue/20">
                        <svg class="w-6 h-6 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h3 class="text-xl font-heading font-black text-gray-900 mb-3 tracking-tight">Our Mission</h3>
                    <p class="text-sm text-gray-600 font-medium leading-relaxed">To bring people into an experience of God’s unconditional love that transforms them into men and women passionate about influencing their world through the principles of the Kingdom.</p>
                </div>
                <div class="glass-panel p-8 rounded-3xl hover:-translate-y-1 transition-transform duration-300">
                    <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mb-5 border border-gray-200">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                    </div>
                    <h3 class="text-xl font-heading font-black text-gray-900 mb-3 tracking-tight">Our Values</h3>
                    <p class="text-sm text-gray-600 font-medium leading-relaxed">Integrity, Sharing our faith boldly, Excellence (Global mindset), Excitement, Acceptance, Commitment, and Teamwork.</p>
                </div>
            </div>

            <!-- Routing Hub (Action Buttons) -->
            <div class="glass-panel p-6 md:p-8 rounded-[2rem] space-y-4 max-w-4xl mx-auto">
                <button onclick="startConnectionWizard()" class="w-full bg-hodRed hover:bg-hodBlue text-white py-5 rounded-2xl font-black text-lg md:text-xl uppercase tracking-widest transition-all duration-300 shadow-xl shadow-hodRed/20 flex justify-center items-center gap-3 group">
                    I'm New Here (Let's Connect)
                    <svg class="w-6 h-6 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
                </button>
                
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 pt-2">
                    <a href="/auth/login.php" class="bg-white/80 hover:bg-white text-gray-800 border border-gray-200 py-4 rounded-xl font-bold uppercase tracking-wider text-xs transition-all text-center flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5">
                        <svg class="w-4 h-4 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg> Already Family
                    </a>
                    <a href="/parent_portal.php" class="bg-white/80 hover:bg-white text-gray-800 border border-gray-200 py-4 rounded-xl font-bold uppercase tracking-wider text-xs transition-all text-center flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5">
                        <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> Parent Portal
                    </a>
                    <!-- NEW LIBRARY BUTTON -->
                    <a href="/modules/library/index.php" class="bg-white/80 hover:bg-white text-gray-800 border border-gray-200 py-4 rounded-xl font-bold uppercase tracking-wider text-xs transition-all text-center flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5">
                        <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg> Library
                    </a>
                    <a href="/testimonies.php" class="bg-white/80 hover:bg-white text-gray-800 border border-gray-200 py-4 rounded-xl font-bold uppercase tracking-wider text-xs transition-all text-center flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5">
                        <svg class="w-4 h-4 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg> Testimonies
                    </a>
                    <a href="/sermons.php" class="bg-white/80 hover:bg-white text-gray-800 border border-gray-200 py-4 rounded-xl font-bold uppercase tracking-wider text-xs transition-all text-center flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5">
                        <svg class="w-4 h-4 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Sermons
                    </a>
                </div>
            </div>

        </div>
    </div>

    <!-- CONNECTION WIZARD (Hidden initially) -->
    <div id="wizardView" class="hidden relative z-20 flex flex-col items-center justify-center min-h-screen p-4 md:p-6 fade-in">
        <div class="glass-panel bg-white/95 w-full max-w-2xl rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            
            <!-- Wizard Header -->
            <div class="p-6 md:p-8 border-b border-gray-100 shrink-0 flex justify-between items-center bg-gray-50/80">
                <div>
                    <h2 class="text-2xl font-heading font-black text-gray-900 tracking-tight" id="wizardTitle">Let's get acquainted</h2>
                    <div class="flex gap-2 mt-3">
                        <div id="dot1" class="w-8 h-1.5 rounded-full bg-hodRed transition-all"></div>
                        <div id="dot2" class="w-8 h-1.5 rounded-full bg-gray-200 transition-all"></div>
                        <div id="dot3" class="w-8 h-1.5 rounded-full bg-gray-200 transition-all"></div>
                    </div>
                </div>
                <button onclick="cancelWizard()" class="text-gray-400 hover:text-hodRed p-2 bg-white border border-gray-200 rounded-full shadow-sm transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <!-- Error banner (filled by showError) -->
            <div id="wizardError" class="hidden shrink-0 px-6 md:px-8 pt-5 bg-white" role="alert" aria-live="assertive">
                <div class="flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-2xl">
                    <svg class="w-5 h-5 text-hodRed shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-[10px] font-black text-hodRed uppercase tracking-widest mb-1">Please check this</p>
                        <p id="wizardErrorText" class="text-sm font-bold text-gray-900 leading-snug"></p>
                        <div id="wizardErrorLogin" class="hidden flex flex-wrap gap-2 mt-3">
                            <a href="/auth/login.php" class="bg-hodRed hover:bg-hodBlue text-white px-4 py-2 rounded-lg font-black uppercase tracking-wider text-[10px] shadow-sm transition-colors">Log in as Family</a>
                            <a href="/auth/setup_password.php" class="bg-white hover:bg-gray-100 text-gray-700 border border-gray-200 px-4 py-2 rounded-lg font-bold uppercase tracking-wider text-[10px] shadow-sm transition-colors">Find my login email</a>
                        </div>
                    </div>
                    <button type="button" onclick="dismissError()" class="text-gray-400 hover:text-hodRed p-1 -m-1 transition-colors" aria-label="Dismiss">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>

            <!-- Form Body. novalidate: the browser can't show its own bubble on a
                 field in a hidden step, so it would block the submit silently.
                 validateStep() and the API do the checking instead. -->
            <form id="connectForm" novalidate class="flex-1 overflow-y-auto custom-scrollbar p-6 md:p-8 bg-white">
                <input type="hidden" name="action" value="submit_connect_card">
                
                <!-- STEP 1: The Handshake -->
                <div id="step1" class="space-y-6 animate-fade-in-up">
                    <p class="text-gray-500 font-medium text-sm">We are so glad you are here! What should we call you?</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">First Name *</label>
                            <input type="text" name="first_name" required maxlength="50" autocomplete="given-name" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Last Name *</label>
                            <input type="text" name="last_name" required maxlength="50" autocomplete="family-name" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Phone Number *</label>
                        <input type="tel" name="phone" required maxlength="25" autocomplete="tel" inputmode="tel" placeholder="e.g. 0803 123 4567 (for your welcome text)" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Email (Optional)</label>
                        <input type="email" name="email" maxlength="100" autocomplete="email" inputmode="email" placeholder="Your personal email, e.g. name@gmail.com" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm">
                    </div>
                </div>

                <!-- STEP 2: Demographics -->
                <div id="step2" class="hidden space-y-6 animate-fade-in-up">
                    <p class="text-gray-500 font-medium text-sm">Tell us a little more so we can serve and celebrate you better.</p>
                    <div class="grid grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Gender</label>
                            <select name="gender" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm cursor-pointer">
                                <option value="">Select...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Marital Status</label>
                            <select name="marital_status" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm cursor-pointer">
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Date of Birth (Optional)</label>
                        <input type="date" name="dob" min="1900-01-01" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm cursor-text">
                    </div>
                    <div class="relative z-50">
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Where do you live? (Type to search)</label>
                        
                        <!-- Geoapify injects the widget here. Tailwind classes added to match your styling. -->
                        <div id="autocomplete-container" class="w-full bg-gray-50 border border-gray-200 rounded-xl focus-within:border-hodRed focus-within:ring-2 focus-within:ring-hodRed/20 transition-all shadow-sm"></div>
                        
                        <!-- Hidden coordinate and address fields for PHP submission -->
                        <input type="hidden" name="physical_address" id="connectAddress">
                        <input type="hidden" name="latitude" id="connectLat">
                        <input type="hidden" name="longitude" id="connectLng">
                    </div>
                </div>

                <!-- STEP 3: Spiritual Journey -->
                <div id="step3" class="hidden space-y-6 animate-fade-in-up">
                    <p class="text-gray-500 font-medium text-sm">How can we stand with you and support your spiritual journey?</p>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Who invited you? (Optional)</label>
                        <input type="text" name="invited_by" maxlength="150" placeholder="Name of a friend or 'Social Media'" class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm">
                    </div>
                    
                    <div class="space-y-3 pt-2">
                        <label class="flex items-center gap-4 p-4 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-white hover:border-hodRed/50 transition-colors group">
                            <input type="checkbox" name="wants_to_join" value="1" class="w-5 h-5 text-hodRed rounded border-gray-300 focus:ring-hodRed cursor-pointer">
                            <span class="font-bold text-gray-800 text-sm group-hover:text-hodRed transition-colors">I am looking to make HOD my home church.</span>
                        </label>
                        <label class="flex items-center gap-4 p-4 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-white hover:border-hodBlue/50 transition-colors group">
                            <input type="checkbox" name="wants_visitation" value="1" class="w-5 h-5 text-hodBlue rounded border-gray-300 focus:ring-hodBlue cursor-pointer">
                            <span class="font-bold text-gray-800 text-sm group-hover:text-hodBlue transition-colors">I would like a Pastor or minister to call/visit me.</span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">How can we pray for you today?</label>
                        <textarea name="prayer_requests" rows="3" maxlength="2000" placeholder="Our Zoe Intercessory team is ready to agree with you..." class="w-full px-4 py-3.5 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-hodRed focus:ring-2 focus:ring-hodRed/20 transition-all shadow-sm resize-none"></textarea>
                    </div>
                </div>

            </form>

            <!-- Wizard Footer Controls -->
            <div class="p-6 border-t border-gray-100 bg-gray-50/80 shrink-0 flex justify-between gap-4">
                <button type="button" id="prevBtn" onclick="nextStep(-1)" class="hidden w-1/3 bg-white text-gray-700 border border-gray-200 py-3.5 rounded-xl font-bold uppercase tracking-wider text-xs transition-all hover:bg-gray-100 shadow-sm">Back</button>
                <button type="button" id="nextBtn" onclick="nextStep(1)" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-3.5 rounded-xl font-bold uppercase tracking-widest text-xs transition-all shadow-md">Next Step ➔</button>
                <button type="submit" form="connectForm" id="submitWizardBtn" class="hidden w-2/3 bg-hodRed hover:bg-hodBlue text-white py-3.5 rounded-xl font-black uppercase tracking-widest text-xs transition-all duration-300 shadow-lg hover:shadow-hodBlue/30 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg> Welcome Me Home
                </button>
            </div>
        </div>
    </div>

    <!-- SUCCESS VIEW (Hidden initially) -->
    <div id="successView" class="hidden relative z-20 flex flex-col items-center justify-center min-h-screen p-6 text-center fade-in">
        <div class="glass-panel max-w-lg w-full p-10 md:p-12 rounded-[2.5rem] shadow-2xl">
            <div class="w-20 h-20 bg-hodRed/10 text-hodRed border border-hodRed/20 rounded-full flex items-center justify-center mx-auto mb-6 shadow-sm">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h2 class="text-3xl font-heading font-black text-gray-900 mb-4 tracking-tight">We've Received You!</h2>
            <p class="text-gray-600 text-base font-medium leading-relaxed mb-8">
                Welcome to the family. A member of our Embrace Team will reach out to you shortly. We pray this marks the beginning of an incredible journey in your walk with God.
            </p>
            <div class="space-y-3">
                <a href="/testimonies.php" class="block w-full bg-hodBlue hover:bg-gray-900 text-white py-4 rounded-xl font-bold uppercase tracking-widest text-xs transition-all shadow-md">Read Testimonies on the Wall</a>
                <a href="/index.php" class="block w-full bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 py-4 rounded-xl font-bold uppercase tracking-widest text-xs transition-all shadow-sm">Return Home</a>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        const API_URL = '/api/embrace_public_api.php';
        const SYSTEM_EMAIL_DOMAIN = '@hodlc.com';
        const NAME_PATTERN = /^\p{L}[\p{L}\p{M} .'’-]*$/u;
        const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

        let currentStep = 1;
        let busy = false;
        let errorField = null;
        const totalSteps = 3;
        const titles = ["Let's get acquainted", "Getting to know you", "Your Spiritual Journey"];

        // Which step each field lives on, so an error can open the right one.
        const fieldSteps = {
            first_name: 1, last_name: 1, phone: 1, email: 1,
            gender: 2, marital_status: 2, dob: 2, physical_address: 2,
            invited_by: 3, wants_to_join: 3, wants_visitation: 3, prayer_requests: 3
        };

        function todayISO() {
            const d = new Date();
            return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        }

        function field(name) { return $(`#connectForm [name="${name}"]`); }

        // The address is a widget: outline its box and focus the text box inside it.
        function fieldBox(name) { return name === 'physical_address' ? $('#autocomplete-container') : field(name); }
        function fieldInput(name) { return name === 'physical_address' ? $('#autocomplete-container input').first() : field(name); }

        // Address: whatever is typed counts, even without picking a suggestion;
        // picking one also records its coordinates.
        function initAddressField() {
            const container = document.getElementById('autocomplete-container');
            if (!container) return;

            const setAddress = (text, lat = '', lng = '') => {
                $('#connectAddress').val(text);
                $('#connectLat').val(lat);
                $('#connectLng').val(lng);
            };

            container.addEventListener('input', function(e) {
                const text = (e.target.value || '').trim();
                if (text !== $('#connectAddress').val()) setAddress(text);
            });

            // Enter picks a suggestion; it must not submit or advance the wizard.
            container.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });

            try {
                if (typeof autocomplete === 'undefined') throw new Error('Geoapify script did not load');
                const addressWidget = new autocomplete.GeocoderAutocomplete(
                    container,
                    "7a189b607e9e4c4cbedf6ada291b6bc1",
                    {
                        placeholder: "Start typing your street or estate...",
                        filter: { countrycode: ['ng'] }
                    }
                );
                addressWidget.on('select', (location) => {
                    if (location) setAddress(location.properties.formatted, location.properties.lat, location.properties.lon);
                    else setAddress('');
                });
            } catch (err) {
                // Suggestions unavailable (script blocked, offline): a plain box still works.
                console.warn('Address suggestions unavailable:', err);
                container.innerHTML = '<input type="text" class="geoapify-autocomplete-input" maxlength="255" placeholder="Your street, estate or area">';
            }
        }

        $(document).ready(function() {
            field('dob').attr('max', todayISO());
            initAddressField();
        });

        // ---------- Errors ----------

        function clearFieldError(name) {
            if (!name) return;
            fieldBox(name).removeClass('field-invalid');
            fieldInput(name).removeAttr('aria-invalid');
            $(`#connectForm .field-error-msg[data-for="${name}"]`).remove();
            if (name === errorField) dismissError();
        }

        function dismissError() {
            $('#wizardError').addClass('hidden');
            errorField = null;
        }

        function clearErrors() {
            $('#connectForm .field-invalid').removeClass('field-invalid');
            $('#connectForm [aria-invalid]').removeAttr('aria-invalid');
            $('#connectForm .field-error-msg').remove();
            dismissError();
        }

        // Opens the step holding the field, outlines it, shows the message under
        // it and in the banner, and puts the cursor there so it can be fixed.
        function showError(message, name, opts = {}) {
            clearErrors();
            if (name && fieldSteps[name]) goToStep(fieldSteps[name]);

            errorField = name && fieldSteps[name] ? name : null;
            $('#wizardErrorText').text(message);
            $('#wizardErrorLogin').toggleClass('hidden', !opts.exists);
            $('#wizardError').removeClass('hidden');

            if (!errorField) return;
            const box = fieldBox(errorField);
            const anchor = box.is(':checkbox') ? box.closest('label') : box;
            box.addClass('field-invalid');
            $('<p class="field-error-msg"></p>').attr('data-for', errorField).text(message).insertAfter(anchor);

            const input = fieldInput(errorField);
            input.attr('aria-invalid', 'true');
            setTimeout(function() {
                if (!input.length) return;
                input[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                input.trigger('focus');
            }, 50);
        }

        // A reply that isn't { status: 'success' }: show its message on its field.
        function showServerError(res) {
            if (res && typeof res === 'object' && res.message) {
                showError(res.message, res.field, { exists: !!res.exists });
            } else {
                showError("We got an unexpected reply from the server. Please try again, or ask an usher for help.");
            }
        }

        // The request itself failed: say why instead of a blanket "connection failed".
        function showAjaxFailure(xhr, textStatus) {
            try {
                const res = JSON.parse(xhr.responseText || '');
                if (res && res.message) return showServerError(res);
            } catch (e) {}

            console.error('Connect card request failed:', textStatus, xhr.status, xhr.responseText);
            let message;
            if (!navigator.onLine) {
                message = "You seem to be offline. Please check your internet connection and try again. Nothing you typed has been lost.";
            } else if (textStatus === 'timeout') {
                message = "The server is taking too long to respond. Please try again in a moment.";
            } else if (xhr.status === 0) {
                message = "We couldn't reach the server. Please check your internet connection and try again.";
            } else if (textStatus === 'parsererror') {
                message = "The server sent back an unexpected reply. Please try again, or ask an usher for help.";
            } else {
                message = `The server ran into a problem (error ${xhr.status}). Please try again in a moment, or ask an usher for help.`;
            }
            showError(message);
        }

        // Editing a flagged field clears its error.
        $('#connectForm').on('input change', 'input, select, textarea', function() {
            clearFieldError($(this).closest('#autocomplete-container').length ? 'physical_address' : this.name);
        });

        // ---------- Validation (mirrors api/embrace_public_api.php) ----------

        // Returns { name, message } for the first problem on the step, or null.
        function validateStep(step) {
            const val = name => (field(name).val() || '').trim();

            if (step === 1) {
                for (const [name, label] of [['first_name', 'first name'], ['last_name', 'last name']]) {
                    const v = val(name);
                    if (!v) return { name, message: `Please enter your ${label}.` };
                    if (v.length > 50) return { name, message: `Your ${label} is too long. Please keep it under 50 characters.` };
                    if (!NAME_PATTERN.test(v)) return { name, message: `Your ${label} can only contain letters, spaces, hyphens and apostrophes.` };
                }

                const phone = val('phone');
                const digits = phone.replace(/\D/g, '');
                if (!phone) return { name: 'phone', message: 'Please enter your phone number so we can reach you.' };
                if (!/^\+?[0-9 ().-]+$/.test(phone)) return { name: 'phone', message: 'Please use only digits in your phone number, e.g. 0803 123 4567 or +234 803 123 4567.' };
                if (digits.length < 9 || digits.length > 15) return { name: 'phone', message: 'Please enter a valid phone number (9 to 15 digits), e.g. 0803 123 4567.' };

                const email = val('email').toLowerCase();
                if (email) {
                    if (email.length > 100 || !EMAIL_PATTERN.test(email)) return { name: 'email', message: "That email address doesn't look right. Please check it (e.g. name@gmail.com), or leave it blank." };
                    if (email.endsWith(SYSTEM_EMAIL_DOMAIN)) return { name: 'email', message: '@hodlc.com addresses are issued by the church. Please enter your personal email (e.g. name@gmail.com), or leave it blank.' };
                }
            }

            if (step === 2) {
                // A half-typed date reads as '' but is flagged by the browser as bad input.
                const dobInput = field('dob')[0];
                if (dobInput.validity && dobInput.validity.badInput) return { name: 'dob', message: 'Your date of birth is incomplete. Please finish it, or clear it.' };
                const dob = dobInput.value;
                if (dob && dob > todayISO()) return { name: 'dob', message: "Your date of birth can't be in the future." };
                if (dob && dob < '1900-01-01') return { name: 'dob', message: 'Please enter a valid date of birth, or leave it blank.' };
                if (val('physical_address').length > 255) return { name: 'physical_address', message: 'Your address is too long. Please shorten it (255 characters max).' };
            }

            if (step === 3) {
                if (val('invited_by').length > 150) return { name: 'invited_by', message: 'Your answer to "Who invited you?" is too long. Please shorten it (150 characters max).' };
                if (val('prayer_requests').length > 2000) return { name: 'prayer_requests', message: 'Your prayer request is too long. Please shorten it (2,000 characters max).' };
            }

            return null;
        }

        // ---------- Navigation ----------

        function startConnectionWizard() {
            $('#landingView').addClass('hidden');
            $('#wizardView').removeClass('hidden');
            window.scrollTo(0, 0);
            setTimeout(function() { field('first_name').trigger('focus'); }, 50);
        }

        function cancelWizard() {
            clearErrors();
            goToStep(1);
            $('#wizardView').addClass('hidden');
            $('#landingView').removeClass('hidden');
        }

        function goToStep(step) {
            for (let i = 1; i <= totalSteps; i++) $(`#step${i}`).toggleClass('hidden', i !== step);
            currentStep = step;
            updateUI();
        }

        function nextStep(n) {
            if (busy) return;
            if (n < 0) {
                clearErrors();
                goToStep(Math.max(1, currentStep - 1));
                return;
            }

            const problem = validateStep(currentStep);
            if (problem) return showError(problem.message, problem.name);

            if (currentStep === 1) return checkIdentity();

            clearErrors();
            goToStep(Math.min(totalSteps, currentStep + 1));
        }

        // Step 1 is checked on the server (existing phone/email) before moving on.
        function checkIdentity() {
            const btn = $('#nextBtn');
            const origText = btn.html();
            busy = true;
            btn.prop('disabled', true).html('Checking Details...');

            $.ajax({
                url: API_URL,
                type: 'POST',
                dataType: 'json',
                timeout: 20000,
                data: {
                    action: 'check_existing_user',
                    first_name: field('first_name').val(),
                    last_name: field('last_name').val(),
                    phone: field('phone').val(),
                    email: field('email').val()
                }
            }).done(function(res) {
                if (res && res.status === 'success') {
                    clearErrors();
                    goToStep(2);
                } else {
                    showServerError(res);
                }
            }).fail(showAjaxFailure).always(function() {
                busy = false;
                btn.prop('disabled', false).html(origText);
            });
        }

        function updateUI() {
            $('#wizardTitle').text(titles[currentStep - 1]);

            // Dot Indicators
            for(let i=1; i<=totalSteps; i++) {
                if(i <= currentStep) $(`#dot${i}`).removeClass('bg-gray-200').addClass('bg-hodRed');
                else $(`#dot${i}`).removeClass('bg-hodRed').addClass('bg-gray-200');
            }

            // Buttons
            if (currentStep === 1) {
                $('#prevBtn').addClass('hidden');
                $('#nextBtn').removeClass('hidden').addClass('w-full').removeClass('w-2/3');
                $('#submitWizardBtn').addClass('hidden');
            } else if (currentStep === totalSteps) {
                $('#prevBtn').removeClass('hidden');
                $('#nextBtn').addClass('hidden');
                $('#submitWizardBtn').removeClass('hidden');
            } else {
                $('#prevBtn').removeClass('hidden');
                $('#nextBtn').removeClass('hidden').removeClass('w-full').addClass('w-2/3');
                $('#submitWizardBtn').addClass('hidden');
            }
        }

        // Enter moves to the next step (or sends the card on the last one).
        // Without this the browser submits through the hidden button and skips steps.
        $('#connectForm').on('keydown', 'input:not([type="checkbox"])', function(e) {
            if (e.key !== 'Enter' || $(this).closest('#autocomplete-container').length) return;
            e.preventDefault();
            if (currentStep < totalSteps) nextStep(1);
            else $('#connectForm').trigger('submit');
        });

        $('#connectForm').on('submit', function(e) {
            e.preventDefault();
            if (busy) return;
            if (currentStep < totalSteps) return nextStep(1);

            // Re-check every step: the first problem found opens its step.
            for (let s = 1; s <= totalSteps; s++) {
                const problem = validateStep(s);
                if (problem) return showError(problem.message, problem.name);
            }

            const btn = $('#submitWizardBtn');
            const orig = btn.html();
            busy = true;
            clearErrors();
            btn.prop('disabled', true).html('<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...');

            $.ajax({
                url: API_URL,
                type: 'POST',
                data: new FormData(this),
                contentType: false, processData: false, dataType: 'json',
                timeout: 30000
            }).done(function(res) {
                if (res && res.status === 'success') {
                    $('#wizardView').addClass('hidden');
                    $('#successView').removeClass('hidden');
                    window.scrollTo(0, 0);
                } else {
                    showServerError(res);
                }
            }).fail(showAjaxFailure).always(function() {
                busy = false;
                btn.prop('disabled', false).html(orig);
            });
        });
    </script>
</body>
</html>