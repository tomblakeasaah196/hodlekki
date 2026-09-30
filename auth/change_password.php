<?php
// /auth/change_password.php
//
// Standalone password-change screen. Two jobs:
//   1. The landing page when users.must_change_password = 1 (e.g. straight
//      after an admin reset). includes/header.php bounces every authenticated
//      page here until the password is changed, so this file deliberately does
//      NOT include header.php — that would be a redirect loop.
//   2. A direct link for anyone who just wants to change their password
//      without going through My Profile.

require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$forced = false;
try {
    if (security_schema_ready($pdo)) {
        $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $forced = (int) $stmt->fetchColumn() === 1;
    }
} catch (Throwable $e) {
    error_log('change_password.php status check failed: ' . $e->getMessage());
}

$firstName = htmlspecialchars($_SESSION['first_name'] ?? 'there');
$minLength = SECURITY_MIN_PASSWORD_LENGTH;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $forced ? 'Set a New Password' : 'Change Password' ?> | HOD Lekki Centre</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
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
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
</head>
<body class="bg-gradient-to-br from-hodBlue via-[#152750] to-[#0D1830] font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg">

        <div class="flex justify-center mb-8">
            <img src="/assets/images/hod_logo.svg" alt="HOD Lekki Centre" class="h-14 object-contain brightness-0 invert"
                 onerror="this.src='https://placehold.co/150x50?text=HOD+Logo'">
        </div>

        <div class="bg-white rounded-3xl shadow-2xl overflow-hidden">

            <div class="px-8 pt-8 pb-6 border-b border-gray-100">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-red-700 flex items-center justify-center text-white shadow-lg mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h1 class="text-2xl font-display font-bold text-gray-900 tracking-tight">
                    <?= $forced ? 'Set a new password' : 'Change your password' ?>
                </h1>
                <p class="text-gray-500 text-sm mt-1">
                    <?php if ($forced): ?>
                        Hi <?= $firstName ?>, an administrator has asked you to choose a new password before you continue.
                    <?php else: ?>
                        Hi <?= $firstName ?>, enter your current password and pick a new one.
                    <?php endif; ?>
                </p>
            </div>

            <form id="pwForm" class="p-8 space-y-5" autocomplete="off">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="sign_out_others" value="1">

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-wider mb-2">Current Password</label>
                    <input type="password" name="current_password" id="curPw" required autocomplete="current-password"
                        class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all text-gray-800 font-semibold"
                        placeholder="<?= $forced ? 'The temporary password you were given' : '••••••••' ?>">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-wider mb-2">New Password</label>
                    <input type="password" name="new_password" id="newPw" required autocomplete="new-password"
                        class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all text-gray-800 font-semibold"
                        placeholder="At least <?= (int) $minLength ?> characters">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-wider mb-2">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confPw" required autocomplete="new-password"
                        class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all text-gray-800 font-semibold"
                        placeholder="Type it once more">
                </div>

                <label class="flex items-center gap-2 text-xs text-gray-500 font-medium cursor-pointer select-none">
                    <input type="checkbox" id="showPw" class="w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                    Show passwords
                </label>

                <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4">
                    <p class="text-[11px] font-bold text-gray-700 uppercase tracking-wider mb-2">Your new password needs</p>
                    <ul class="space-y-1 text-[11px] font-semibold text-gray-500">
                        <?php foreach (security_password_rules() as $rule): ?>
                            <li class="flex items-start gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-300 mt-1.5 shrink-0"></span>
                                <?= htmlspecialchars($rule) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <button type="submit" id="pwSubmit"
                    class="w-full bg-hodRed hover:bg-red-700 text-white font-semibold py-3.5 rounded-xl shadow-lg shadow-red-500/30 transition-all flex justify-center items-center gap-2">
                    Save New Password
                </button>

                <div class="flex items-center justify-between pt-1">
                    <?php if (!$forced): ?>
                        <a href="/modules/profile/index.php#security" class="text-xs font-bold text-gray-500 hover:text-gray-900 transition-colors">Back to My Profile</a>
                    <?php else: ?>
                        <span class="text-xs text-gray-400 font-medium">You cannot skip this step.</span>
                    <?php endif; ?>
                    <a href="/auth/logout.php" class="text-xs font-bold text-hodRed hover:text-red-700 transition-colors">Sign out</a>
                </div>
            </form>
        </div>

        <p class="text-center text-xs text-white/40 mt-8">
            &copy; <?= date('Y') ?> Household of David, Lekki Centre.
        </p>
    </div>

    <script>
        const FORCED = <?= $forced ? 'true' : 'false' ?>;

        function toast(msg, type) {
            Toastify({
                text: msg,
                gravity: 'top',
                position: 'center',
                duration: 5000,
                style: {
                    background: type === 'success' ? '#10B981' : '#EF4444',
                    borderRadius: '12px',
                    fontWeight: 'bold'
                }
            }).showToast();
        }

        $('#showPw').on('change', function() {
            const t = this.checked ? 'text' : 'password';
            $('#curPw, #newPw, #confPw').attr('type', t);
        });

        $('#pwForm').on('submit', function(e) {
            e.preventDefault();

            if ($('#newPw').val() !== $('#confPw').val()) {
                toast('The new password and its confirmation do not match.', 'error');
                return;
            }

            const btn = $('#pwSubmit');
            const original = btn.html();
            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').text('Saving...');

            $.post('/api/profile_api.php', $(this).serialize(), function(res) {
                if (res.status === 'success') {
                    toast('Password updated. Taking you in...', 'success');
                    setTimeout(function() {
                        window.location.href = FORCED ? '/index.php' : '/modules/profile/index.php#security';
                    }, 1200);
                } else {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(original);
                    toast(res.message || 'Could not update the password.', 'error');
                }
            }, 'json').fail(function() {
                btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(original);
                toast('Network error. Please try again.', 'error');
            });
        });
    </script>
</body>
</html>
