<?php
// /auth/setup_password.php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Access | HOD Lekki Centre</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1D356A', hodRed: '#D11920' },
                    fontFamily: { sans: ['Inter', 'sans-serif'], display: ['Montserrat', 'sans-serif'] }
                }
            }
        }
    </script>
    
    <style>
        /* Hide scrollbar for Chrome, Safari and Opera */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        /* Hide scrollbar for IE, Edge and Firefox */
        .no-scrollbar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
</head>
<body class="bg-black font-sans relative antialiased overflow-x-hidden no-scrollbar">
    
    <!-- Fixed Background -->
    <div class="fixed inset-0 z-0">
        <img src="https://images.unsplash.com/photo-1507692049790-de58290a4334?auto=format&fit=crop&w=2000&q=80" class="w-full h-full object-cover opacity-50">
        <div class="absolute inset-0 bg-hodBlue/40 mix-blend-multiply"></div>
        <div class="absolute inset-0 bg-gradient-to-br from-black/90 via-black/50 to-hodBlue/90"></div>
    </div>

    <!-- Scrollable Page Wrapper -->
    <div class="relative z-10 min-h-screen flex flex-col p-4 sm:p-8">
        
        <!-- The Card (Grows organically with content) -->
        <div class="w-full max-w-md m-auto bg-white/10 backdrop-blur-2xl border border-white/20 rounded-3xl p-6 sm:p-8 shadow-2xl transition-all duration-300">
            
            <div class="text-center mb-8">
                <h1 class="text-2xl font-display font-bold text-white tracking-tight">Security Setup</h1>
                <p id="subheading" class="text-blue-100/70 text-sm mt-2">Enter your registered email to set a new password.</p>
            </div>

            <div id="alert-box" class="hidden mb-6 p-4 rounded-xl text-sm font-medium transition-all shadow-sm"></div>

            <!-- ========================================== -->
            <!-- VIEW 1: PASSWORD SETUP FORM (Default)      -->
            <!-- ========================================== -->
            <form id="setupForm" class="space-y-5">
                <input type="hidden" name="action" value="setup_password">
                
                <div>
                    <label class="block text-xs font-semibold text-blue-100 uppercase tracking-widest mb-2">Registered Email</label>
                    <input type="email" name="email" id="setup_email" required 
                        class="w-full px-4 py-3 rounded-xl border border-white/10 bg-white/5 focus:bg-white/10 focus:ring-2 focus:ring-hodRed transition-all outline-none text-white placeholder-white/30"
                        placeholder="email@example.com">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-blue-100 uppercase tracking-widest mb-2">New Password</label>
                    <input type="password" name="new_password" id="new_password" required 
                        class="w-full px-4 py-3 rounded-xl border border-white/10 bg-white/5 focus:bg-white/10 focus:ring-2 focus:ring-hodRed transition-all outline-none text-white placeholder-white/30"
                        placeholder="••••••••">
                    
                    <div class="mt-2 text-[10px] font-medium text-white/50 tracking-wide space-y-1">
                        <p>Password must contain at least:</p>
                        <ul class="list-disc pl-4 text-white/40">
                            <li>8 characters</li>
                            <li>1 uppercase letter</li>
                            <li>1 number</li>
                        </ul>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-blue-100 uppercase tracking-widest mb-2">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required 
                        class="w-full px-4 py-3 rounded-xl border border-white/10 bg-white/5 focus:bg-white/10 focus:ring-2 focus:ring-hodRed transition-all outline-none text-white placeholder-white/30"
                        placeholder="••••••••">
                </div>

                <button type="submit" id="submitBtn" class="w-full bg-hodRed hover:bg-red-700 text-white font-bold py-4 rounded-xl shadow-lg transition-all flex justify-center items-center gap-2">
                    <span>Update Credentials</span>
                    <svg id="spinner" class="animate-spin hidden h-5 w-5 text-white" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </button>

                <div class="flex flex-col items-center gap-3 mt-4">
                    <button type="button" id="showLookupBtn" class="text-xs font-bold text-white hover:text-hodRed transition-colors underline decoration-white/30 underline-offset-4">
                        Forgot your system email? Look it up.
                    </button>
                    <a href="/auth/login.php" class="text-xs font-semibold text-blue-200/50 hover:text-white transition-colors">
                        Back to Login
                    </a>
                </div>
            </form>

            <!-- ========================================== -->
            <!-- VIEW 2: EMAIL LOOKUP FORM (Hidden initially) -->
            <!-- ========================================== -->
            <form id="lookupForm" class="space-y-5 hidden">
                <input type="hidden" name="action" value="lookup_email">
                
                <div>
                    <label class="block text-xs font-semibold text-blue-100 uppercase tracking-widest mb-2">First Name</label>
                    <input type="text" name="first_name" required 
                        class="w-full px-4 py-3 rounded-xl border border-white/10 bg-white/5 focus:bg-white/10 focus:ring-2 focus:ring-hodBlue transition-all outline-none text-white placeholder-white/30"
                        placeholder="E.g. David">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-blue-100 uppercase tracking-widest mb-2">Registered Phone</label>
                    <input type="tel" name="phone" required 
                        class="w-full px-4 py-3 rounded-xl border border-white/10 bg-white/5 focus:bg-white/10 focus:ring-2 focus:ring-hodBlue transition-all outline-none text-white placeholder-white/30"
                        placeholder="08012345678">
                </div>

                <button type="submit" id="lookupBtn" class="w-full bg-hodBlue hover:bg-blue-900 text-white font-bold py-4 rounded-xl shadow-lg transition-all flex justify-center items-center gap-2">
                    <span>Find My Email</span>
                    <svg id="lookupSpinner" class="animate-spin hidden h-5 w-5 text-white" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </button>

                <button type="button" id="showSetupBtn" class="w-full block text-center text-xs font-semibold text-blue-200 hover:text-white transition-colors mt-4">
                    Nevermind, I remember it
                </button>
            </form>
        </div>
    </div>

    <script>
        function showAlert(message, type) {
            const alertBox = $('#alert-box');
            alertBox.removeClass('hidden bg-red-500/20 text-red-200 border-red-500/30 bg-green-500/20 text-green-200 border-green-500/30');
            
            if (type === 'error') {
                alertBox.addClass('bg-red-500/20 text-red-200 border border-red-500/30').html(message);
            } else {
                alertBox.addClass('bg-green-500/20 text-green-200 border border-green-500/30').html(message);
            }
        }

        // --- View Toggles ---
        $('#showLookupBtn').click(function(e) {
            e.preventDefault();
            $('#alert-box').addClass('hidden');
            $('#subheading').text('Find your system email using your phone and first name.');
            $('#setupForm').slideUp(300, function() {
                $('#lookupForm').slideDown(300);
            });
        });

        $('#showSetupBtn').click(function(e) {
            e.preventDefault();
            $('#alert-box').addClass('hidden');
            $('#subheading').text('Enter your registered email to set a new password.');
            $('#lookupForm').slideUp(300, function() {
                $('#setupForm').slideDown(300);
            });
        });

        // --- Action: Lookup Email ---
        $('#lookupForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#lookupBtn');
            btn.prop('disabled', true).addClass('opacity-70');
            $('#lookupSpinner').removeClass('hidden');
            $('#alert-box').addClass('hidden');

            $.post('/api/setup_password_api.php', $(this).serialize(), function(res) {
                btn.prop('disabled', false).removeClass('opacity-70');
                $('#lookupSpinner').addClass('hidden');

                if(res.status === 'success') {
                    // Pre-fill the email in the setup form
                    $('#setup_email').val(res.email);
                    
                    // Show success message with the email, and slide back to Setup form
                    showAlert(`<strong>Found it!</strong> Your email is <br><span class="text-white text-base block mt-1">${res.email}</span>`, 'success');
                    
                    $('#subheading').text('Your email has been pre-filled. Now set your password.');
                    $('#lookupForm').slideUp(300, function() {
                        $('#setupForm').slideDown(300);
                    });
                } else {
                    showAlert(res.message, 'error');
                }
            }, 'json').fail(function() {
                btn.prop('disabled', false).removeClass('opacity-70');
                $('#lookupSpinner').addClass('hidden');
                showAlert('Network error. Please try again.', 'error');
            });
        });

        // --- Action: Setup Password ---
        $('#setupForm').on('submit', function(e) {
            e.preventDefault();
            
            const pass = $('#new_password').val();
            const confirmPass = $('#confirm_password').val();

            // Frontend Validation: Match check
            if (pass !== confirmPass) {
                showAlert("Passwords do not match. Please try again.", 'error');
                return;
            }

            // Frontend Validation: Complexity Rules
            const hasMinLength = pass.length >= 8;
            const hasUpper = /[A-Z]/.test(pass);
            const hasNumber = /[0-9]/.test(pass);

            if (!hasMinLength || !hasUpper || !hasNumber) {
                showAlert("Password must be at least 8 chars, 1 uppercase, and 1 number.", 'error');
                return;
            }

            const btn = $('#submitBtn');
            btn.prop('disabled', true).addClass('opacity-70');
            $('#spinner').removeClass('hidden');
            $('#alert-box').addClass('hidden');

            $.post('/api/setup_password_api.php', $(this).serialize(), function(res) {
                if(res.status === 'success') {
                    showAlert(res.message, 'success');
                    setTimeout(() => { window.location.href = '/auth/login.php'; }, 2000);
                } else {
                    showAlert(res.message, 'error');
                    btn.prop('disabled', false).removeClass('opacity-70');
                    $('#spinner').addClass('hidden');
                }
            }, 'json').fail(function() {
                showAlert('Network error. Please try again.', 'error');
                btn.prop('disabled', false).removeClass('opacity-70');
                $('#spinner').addClass('hidden');
            });
        });
    </script>
</body>
</html>