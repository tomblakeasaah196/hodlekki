<?php
// /verify.php
// House of David Lekki Centre — Public Document Verification Portal
session_start();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Verification | Household of David Lekki Centre</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@600;800;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Inter', 'sans-serif'], 
                        heading: ['Montserrat', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace']
                    },
                    colors: { hodBlue: '#0A0E17', hodRed: '#D11920' },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
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
        
        /* Warm Light Glass-morphism */
        .glass-panel {
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Custom Input styling for verification code */
        .vrf-input {
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }
        .vrf-input::placeholder {
            text-transform: none;
            letter-spacing: normal;
        }
    </style>
</head>
<body class="bg-gray-50 antialiased min-h-screen relative overflow-x-hidden selection:bg-hodBlue selection:text-white flex flex-col">

    <!-- Ambient Background -->
    <div class="fixed inset-0 z-[-1] overflow-hidden bg-white">
        <!-- Abstract structural pattern for a 'secure' feel -->
        <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(#0A0E17 1px, transparent 1px); background-size: 32px 32px;"></div>
        
        <!-- Brand-specific ambient glows -->
        <div class="absolute top-[-10%] right-[-5%] w-[600px] h-[600px] bg-hodBlue/10 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-[-10%] left-[-5%] w-[500px] h-[500px] bg-emerald-500/10 rounded-full blur-[120px]"></div>
    </div>

    <!-- MAIN VIEW -->
    <div class="relative z-10 flex flex-col items-center justify-center flex-1 p-4 md:p-6 fade-in w-full max-w-2xl mx-auto">
        
        <!-- Header -->
        <div class="text-center space-y-4 mb-8">
            <a href="/index.php" class="inline-block transition-transform hover:scale-105">
                <img src="/assets/images/hod_logo.svg" alt="HOD Logo" class="h-16 mx-auto drop-shadow-sm" onerror="this.src='https://placehold.co/200x60/0A0E17/FFF?text=HOD+Lekki'">
            </a>
            <h1 class="text-3xl md:text-4xl font-heading font-black text-gray-900 tracking-tight">Document Verification</h1>
            <p class="text-sm md:text-base text-gray-500 font-medium max-w-md mx-auto">
                Enter the cryptographic code found on your printed or digital document to verify its authenticity.
            </p>
        </div>

        <!-- SEARCH CONTAINER -->
        <div id="searchContainer" class="w-full">
            <div class="glass-panel p-6 md:p-8 rounded-[2rem] w-full animate-fade-in-up">
                <form id="verifyForm" class="space-y-5">
                    <input type="hidden" name="action" value="verify_document">
                    
                    <!-- Honeypot -->
                    <input type="text" name="honeypot" class="hidden" tabindex="-1" autocomplete="off">

                    <div>
                        <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2 ml-1">Verification Code</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                            </div>
                            <input type="text" name="verify_code" id="verifyInput" required placeholder="e.g. VRF-A1B2-C3D4" class="vrf-input w-full pl-11 pr-4 py-4 bg-gray-50/50 border border-gray-200 rounded-xl font-bold text-gray-900 text-lg outline-none focus:border-hodBlue focus:ring-2 focus:ring-hodBlue/20 transition-all shadow-inner">
                        </div>
                    </div>

                    <button type="submit" id="verifyBtn" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-4 rounded-xl font-black uppercase tracking-widest text-sm transition-all shadow-xl shadow-hodBlue/20 flex items-center justify-center gap-2 group">
                        <span>Verify Document</span>
                        <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </form>
            </div>
            
            <!-- Trust Badges -->
            <div class="flex items-center justify-center gap-6 mt-8 opacity-60">
                <div class="flex items-center gap-2 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    SHA-256 Secured
                </div>
                <div class="flex items-center gap-2 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                    HODLC System
                </div>
            </div>
        </div>

        <!-- RESULT CONTAINER (Hidden initially) -->
        <div id="resultContainer" class="w-full hidden animate-fade-in-up">
            <div class="bg-white rounded-[2rem] shadow-2xl border border-gray-100 overflow-hidden relative">
                
                <!-- Top Status Bar -->
                <div class="bg-emerald-500 px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-white">
                        <div class="bg-white/20 p-1 rounded-full">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <span class="font-black tracking-widest uppercase text-sm">Valid & Binding</span>
                    </div>
                    <svg class="w-6 h-6 text-white/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                </div>

                <!-- Certificate Body -->
                <div class="p-6 md:p-8 space-y-6">
                    <div class="text-center pb-6 border-b border-gray-100">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Document Type</p>
                        <h2 id="resType" class="text-xl md:text-2xl font-heading font-black text-gray-900">Departmental Requisition</h2>
                        <p id="resRef" class="text-sm font-bold text-hodBlue mt-1">REQ-XXXX-XXXX</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-6">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Department</p>
                            <p id="resDept" class="text-sm font-bold text-gray-900">Music Department</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Total Amount</p>
                            <p id="resAmount" class="text-base font-black text-emerald-600">₦0.00</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Digitally Validated By</p>
                            <p id="resBy" class="text-sm font-bold text-gray-900">John Doe</p>
                            <p id="resRole" class="text-[10px] text-gray-500 font-medium">Director</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Timestamp</p>
                            <p id="resTime" class="text-sm font-bold text-gray-900">01 Jan 2026</p>
                            <p id="resLevel" class="text-[10px] text-gray-500 font-medium">Director Level Stamp</p>
                        </div>
                    </div>

                    <!-- Hash Block -->
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200 mt-4">
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                            SHA-256 Cryptographic Hash
                        </p>
                        <p id="resHash" class="font-mono text-[10px] text-gray-600 break-all leading-relaxed bg-white p-2 border border-gray-100 rounded-lg shadow-inner select-all">
                            0000000000000000000000000000000000000000000000000000000000000000
                        </p>
                    </div>
                </div>

                <div class="bg-gray-50 p-4 border-t border-gray-100">
                    <button onclick="resetVerification()" class="w-full bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 py-3 rounded-xl font-bold text-sm transition-all shadow-sm">
                        Verify Another Document
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- Page Footer -->
    <footer class="relative z-10 w-full text-center p-6 text-gray-400 text-xs font-medium border-t border-gray-100 bg-white/50 backdrop-blur-md">
        &copy; <?php echo date('Y'); ?> Household of David Lekki Centre. All Rights Reserved.
    </footer>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        $(document).ready(function() {
            
            // Auto-format input to uppercase
            $('#verifyInput').on('input', function() {
                this.value = this.value.toUpperCase();
            });

            $('#verifyForm').on('submit', function(e) {
                e.preventDefault();
                
                const btn = $('#verifyBtn');
                const origText = btn.html();
                
                // Set loading state
                btn.prop('disabled', true).html('<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> <span>Verifying...</span>');
                
                $.ajax({
                    url: '/api/public_verify_api.php',
                    type: 'POST',
                    data: new FormData(this),
                    contentType: false, 
                    processData: false, 
                    dataType: 'json',
                    success: function(res) {
                        btn.prop('disabled', false).html(origText);
                        
                        if(res.status === 'success' && res.data) {
                            // Populate Result Card
                            $('#resType').text(res.data.document_type);
                            $('#resRef').text(res.data.reference);
                            $('#resDept').text(res.data.department);
                            $('#resAmount').text(res.data.amount);
                            $('#resBy').text(res.data.validated_by);
                            $('#resRole').text(res.data.role);
                            $('#resTime').text(res.data.timestamp);
                            $('#resLevel').text(res.data.stamp_level);
                            $('#resHash').text(res.data.cryptographic_hash);

                            // Switch Views
                            $('#searchContainer').addClass('hidden');
                            $('#resultContainer').removeClass('hidden');
                        } else {
                            // Show Error
                            Toastify({ 
                                text: res.message || "Invalid verification code.", 
                                duration: 4000, 
                                gravity: "top", 
                                position: "center",
                                style: { background: "#D11920", borderRadius: "10px", fontWeight: "bold", fontSize: "14px", fontFamily: "Inter", boxShadow: "0 10px 25px rgba(209, 25, 32, 0.3)" } 
                            }).showToast();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html(origText);
                        Toastify({ 
                            text: "Server connection failed. Please check your internet and try again.", 
                            duration: 4000,
                            gravity: "top", 
                            position: "center",
                            style: { background: "#D11920", borderRadius: "10px", fontWeight: "bold", fontSize: "14px", fontFamily: "Inter" } 
                        }).showToast();
                    }
                });
            });
        });

        // Reset UI to verify another document
        function resetVerification() {
            $('#verifyInput').val('').focus();
            $('#resultContainer').addClass('hidden');
            $('#searchContainer').removeClass('hidden');
        }
    </script>
</body>
</html>