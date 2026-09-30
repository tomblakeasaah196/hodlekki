<?php
// /auth/logout.php

// 1. Boot the DB + session (includes/db.php starts the session and pulls in
//    includes/security_helpers.php)
require_once __DIR__ . '/../includes/db.php';

// 2. Retire this browser's row in the session registry so it stops showing as
//    "active" on the admin Security Centre.
if (!empty($_SESSION['user_id'])) {
    try {
        $hash = security_current_session_hash();
        if ($hash !== '' && security_schema_ready($pdo)) {
            $pdo->prepare("
                UPDATE user_sessions
                   SET revoked_at = NOW(), revoke_reason = 'Signed out by the user'
                 WHERE session_hash = ? AND revoked_at IS NULL
            ")->execute([$hash]);
        }
    } catch (Throwable $e) {
        error_log('Logout session cleanup failed: ' . $e->getMessage());
    }
}

// 3. Clear session data, destroy the session cookie and the server-side session
security_destroy_current_session();

// 4. Redirect back to the login page securely
header("Location: /auth/login.php");
exit;
?>
