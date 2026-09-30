<?php
// /auth/login.php
session_start();

// If already logged in, redirect to dashboard using an absolute path
if (isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit;
}

// Constants only — this file has no DB work to do.
require_once __DIR__ . '/../includes/security_helpers.php';

// When the security gate tears a session down (suspension, revoke, "sign out
// everywhere"), it leaves a short-lived cookie explaining why. Show it once,
// then clear it.
$signOutNotice = '';
if (!empty($_COOKIE[SECURITY_SIGNOUT_COOKIE])) {
    $signOutNotice = substr((string) $_COOKIE[SECURITY_SIGNOUT_COOKIE], 0, 300);
    setcookie(SECURITY_SIGNOUT_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome Home | HOD Lekki Centre</title>
    
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
                        'fade-in': 'fadeIn 1.2s ease-out forwards',
                        'slide-up': 'slideUp 0.8s ease-out forwards',
                        'pulse-slow': 'pulse 6s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(30px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        }
                    }
                }
            }
        }
    </script>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <style>
        /* Hide scrollbar for clean aesthetic */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-black font-sans relative antialiased overflow-x-hidden no-scrollbar">

    <!-- Fixed Backgrounds so they don't move when scrolling -->
    <div class="fixed inset-0 z-0">
        <img src="https://images.unsplash.com/photo-1438232992991-995b7058bbb3?ixlib=rb-4.0.3&auto=format&fit=crop&w=2073&q=80" 
             alt="Background" 
             class="w-full h-full object-cover opacity-70 scale-105 transition-transform duration-[30s] hover:scale-100">
        
        <div class="absolute inset-0 bg-hodBlue/40 mix-blend-multiply"></div>
        <div class="absolute inset-0 bg-gradient-to-br from-black/80 via-black/40 to-hodBlue/80"></div>
    </div>

    <!-- Fixed Ambient Glowing Orbs -->
    <div class="fixed top-1/4 left-1/4 w-96 h-96 bg-blue-500/30 rounded-full blur-[100px] animate-pulse-slow z-0"></div>
    <div class="fixed bottom-1/4 right-1/4 w-96 h-96 bg-hodRed/20 rounded-full blur-[100px] animate-pulse-slow z-0" style="animation-delay: 3s;"></div>

    <!-- Scrollable Page Wrapper -->
    <div class="relative z-10 min-h-screen flex flex-col p-4 sm:p-8">
        
        <!-- Main Card (grows organically, margins collapse to allow scrolling) -->
        <div class="w-full max-w-5xl m-auto flex flex-col md:flex-row rounded-3xl overflow-hidden shadow-2xl backdrop-blur-xl bg-white/10 border border-white/20 animate-slide-up min-h-[600px]">
            
            <div class="w-full md:w-5/12 p-8 sm:p-12 flex flex-col justify-between relative order-2 md:order-1 border-t md:border-t-0 md:border-r border-white/10 bg-black/20 md:bg-transparent">
                
                <div class="mb-8 hidden md:block">
                    <h2 class="text-3xl lg:text-4xl font-display font-bold text-white mb-3 tracking-tight">Welcome Home.</h2>
                    <p class="text-blue-100/90 text-sm lg:text-base leading-relaxed font-light">
                        Thank you for serving the Lord with excellence. Your dedication helps us raise a people after God’s heart in every nation.
                    </p>
                </div>

                <div class="min-h-[140px] flex items-center mt-auto">
                    <div id="quote-box" class="text-white transition-opacity duration-1000 w-full">
                        <p id="scripture-text" class="font-display text-lg lg:text-xl font-medium italic leading-snug mb-4 text-white/90">
                            "Let all that you do be done in love."
                        </p>
                        <div class="flex items-center gap-3">
                            <div class="h-[1px] w-8 bg-hodRed/80"></div>
                            <p id="scripture-ref" class="text-xs font-semibold tracking-widest text-blue-200 uppercase">
                                1 Corinthians 16:14
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-7/12 p-8 sm:p-12 lg:p-16 flex flex-col justify-center bg-white/95 order-1 md:order-2 relative shadow-inner">
                
                <div class="w-full max-w-sm mx-auto animate-fade-in" style="animation-delay: 0.3s;">
                    
                    <div class="flex justify-center md:justify-start mb-10">
                        <img src="/assets/images/hod_logo.svg" alt="HOD Lekki Centre" class="h-16 object-contain" onerror="this.src='https://placehold.co/150x50?text=HOD+Logo'">
                    </div>

                    <div class="mb-8 text-center md:text-left">
                        <h1 class="text-2xl font-display font-bold text-gray-900 tracking-tight">Sign In to Workspace</h1>
                        <p class="text-gray-500 text-sm mt-1">Enter your details to access your dashboard.</p>
                    </div>

                    <?php if ($signOutNotice !== ''): ?>
                    <div class="mb-6 p-4 rounded-xl text-sm font-semibold bg-amber-50 text-amber-800 border border-amber-200 shadow-sm flex items-start gap-3">
                        <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <span><?= htmlspecialchars($signOutNotice) ?></span>
                    </div>
                    <?php endif; ?>

                    <div id="alert-box" class="hidden mb-6 p-4 rounded-xl text-sm font-medium transition-all shadow-sm"></div>

                    <form id="loginForm" class="space-y-5">
                        <div>
                            <label for="email" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Email Address</label>
                            <input type="email" id="email" name="email" required 
                                class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 focus:ring-hodBlue focus:border-transparent transition-all bg-gray-50 hover:bg-white focus:bg-white outline-none text-gray-800 shadow-sm"
                                placeholder="yourname@domain.com">
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label for="password" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider">Password</label>
                                <a href="/auth/setup_password.php" class="text-xs font-semibold text-hodRed hover:text-red-700 transition-colors underline decoration-hodRed/30 underline-offset-4">First time? Set your password</a>
                            </div>
                            <input type="password" id="password" name="password" required 
                                class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 focus:ring-hodBlue focus:border-transparent transition-all bg-gray-50 hover:bg-white focus:bg-white outline-none text-gray-800 shadow-sm"
                                placeholder="••••••••">
                        </div>

                        <button type="submit" id="submitBtn" 
                            class="w-full bg-hodRed hover:bg-red-700 text-white font-semibold py-3.5 px-4 rounded-xl shadow-lg shadow-red-500/30 hover:shadow-red-500/50 hover:-translate-y-0.5 transition-all flex justify-center items-center gap-2 mt-4">
                            <span>Authenticate</span>
                            <svg id="spinner" class="animate-spin hidden h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </button>
                    </form>
                    
                    <p class="text-center text-xs text-gray-400 mt-10">
                        &copy; <?php echo date('Y'); ?> Household of David. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 1. Array of 20 Encouraging Scriptures for Service
        const quotes = [
            { text: "\"Let all that you do be done in love.\"", ref: "1 Corinthians 16:14" },
            { text: "\"For God is not unjust so as to overlook your work and the love that you have shown for his name in serving the saints...\"", ref: "Hebrews 6:10" },
            { text: "\"Whatever you do, work heartily, as for the Lord and not for men.\"", ref: "Colossians 3:23" },
            { text: "\"And let us not grow weary of doing good, for in due season we will reap, if we do not give up.\"", ref: "Galatians 6:9" },
            { text: "\"Well done, good and faithful servant. You have been faithful over a little; I will set you over much.\"", ref: "Matthew 25:21" },
            { text: "\"Do not be slothful in zeal, be fervent in spirit, serve the Lord.\"", ref: "Romans 12:11" },
            { text: "\"Whoever brings blessing will be enriched, and one who waters will himself be watered.\"", ref: "Proverbs 11:25" },
            { text: "\"As each has received a gift, use it to serve one another, as good stewards of God's varied grace.\"", ref: "1 Peter 4:10" },
            { text: "\"But as for me and my house, we will serve the Lord.\"", ref: "Joshua 24:15" },
            { text: "\"Serve the Lord with gladness! Come into his presence with singing!\"", ref: "Psalm 100:2" },
            { text: "\"Rendering service with a good will as to the Lord and not to man.\"", ref: "Ephesians 6:7" },
            { text: "\"For even the Son of Man came not to be served but to serve, and to give his life as a ransom for many.\"", ref: "Mark 10:45" },
            { text: "\"If anyone serves me, he must follow me; and where I am, there will my servant be also.\"", ref: "John 12:26" },
            { text: "\"Only fear the Lord and serve him faithfully with all your heart. For consider what great things he has done for you.\"", ref: "1 Samuel 12:24" },
            { text: "\"Present your bodies as a living sacrifice, holy and acceptable to God, which is your spiritual worship.\"", ref: "Romans 12:1" },
            { text: "\"But you, take courage! Do not let your hands be weak, for your work shall be rewarded.\"", ref: "2 Chronicles 15:7" },
            { text: "\"Do not neglect to do good and to share what you have, for such sacrifices are pleasing to God.\"", ref: "Hebrews 13:16" },
            { text: "\"Remembering before our God and Father your work of faith and labor of love...\"", ref: "1 Thessalonians 1:3" },
            { text: "\"Therefore, my beloved brothers, be steadfast, immovable, always abounding in the work of the Lord...\"", ref: "1 Corinthians 15:58" },
            { text: "\"For God has not given us a spirit of fear, but of power and of love and of a sound mind.\"", ref: "2 Timothy 1:7" }
        ];

        let currentQuote = 0;
        
        function rotateQuotes() {
            const quoteBox = $('#quote-box');
            
            // Fade out smoothly
            quoteBox.css('opacity', '0');
            
            setTimeout(() => {
                // Change text while invisible
                currentQuote = (currentQuote + 1) % quotes.length;
                $('#scripture-text').text(quotes[currentQuote].text);
                $('#scripture-ref').text(quotes[currentQuote].ref);
                
                // Fade back in
                quoteBox.css('opacity', '1');
            }, 1000); 
        }

        // Start rotation every 8 seconds
        setInterval(rotateQuotes, 8000);

        // 2. AJAX Login Submission
        $('#loginForm').on('submit', function(e) {
            e.preventDefault(); 
            
            const btn = $('#submitBtn');
            const spinner = $('#spinner');
            const alertBox = $('#alert-box');
            const btnText = btn.find('span');
            
            // Show loading state
            btn.prop('disabled', true).addClass('opacity-90');
            btnText.text('Authenticating...');
            spinner.removeClass('hidden');
            alertBox.addClass('hidden').removeClass('bg-red-50 text-red-600 border border-red-200 bg-green-50 text-green-600 border-green-200');

            $.ajax({
                url: '/api/auth_api.php', // Now using absolute path!
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    alertBox.removeClass('hidden');
                    
                    if(response.status === 'success') {
                        // Success Styling
                        alertBox.addClass('bg-green-50 text-green-600 border border-green-200').text(response.message);
                        btnText.text('Success!');
                        spinner.addClass('hidden');
                        
                        // Redirect dynamically based on backend matrix
                        setTimeout(() => {
                            window.location.href = response.redirect;
                        }, 1000);
                    } else {
                        // Error Styling
                        alertBox.addClass('bg-red-50 text-red-600 border border-red-200').text(response.message);
                        
                        // Reset button
                        btn.prop('disabled', false).removeClass('opacity-90');
                        btnText.text('Authenticate');
                        spinner.addClass('hidden');
                    }
                },
                error: function() {
                    alertBox.removeClass('hidden').addClass('bg-red-50 text-red-600 border border-red-200').text('A network error occurred. Please try again.');
                    btn.prop('disabled', false).removeClass('opacity-90');
                    btnText.text('Authenticate');
                    spinner.addClass('hidden');
                }
            });
        });
    </script>
</body>
</html>