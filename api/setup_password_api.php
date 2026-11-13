<?php
// /api/setup_password_api.php
//
// FIRST-TIME PASSWORD SETUP ONLY.
//
// This endpoint is public (no session required), so it is deliberately
// restricted to accounts that have never had a password set
// (users.password_hash IS NULL OR ''). Before that restriction, anyone who
// knew a member's first name and phone number could take over their account
// from the internet.
//
// Once an account HAS a password:
//   - the member changes it themselves at My Profile → Security (current
//     password required), or
//   - an admin resets it from the Security Centre (modules/security).

require_once '../includes/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$action = $_POST['action'] ?? '';

/** Per-IP throttle so this endpoint can't be used to enumerate members. */
function setup_password_rate_limited(PDO $pdo): bool
{
    if (!security_schema_ready($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
             WHERE context = 'first_time_setup'
               AND was_successful = 0
               AND ip_address = ?
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)
        ");
        $stmt->execute([security_client_ip()]);
        return (int) $stmt->fetchColumn() >= 10;
    } catch (Throwable $e) {
        error_log('setup_password_rate_limited: ' . $e->getMessage());
        return false;
    }
}

try {
    if (setup_password_rate_limited($pdo)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Too many attempts from this connection. Please wait 15 minutes, or contact the church office.',
        ]);
        exit;
    }

    switch ($action) {

        // ==============================================================================
        // SET THE VERY FIRST PASSWORD ON AN ACCOUNT
        //   By email, or by the phone number on the member's record (owner's
        //   decision, 13 Nov 2026: new members get no @hodlc.com name and sign
        //   in with their phone). "Find my email" is gone: the phone signs in.
        // ==============================================================================
        case 'setup_password':
            $typed = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
            $firstName = mb_substr(trim((string) ($_POST['first_name'] ?? '')), 0, 80);
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // 1. Basic empty checks
            if ($typed === '' || empty($new_password) || empty($confirm_password)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
                exit;
            }

            // 2. Password Match Check
            if (!hash_equals($new_password, $confirm_password)) {
                echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
                exit;
            }

            // 3. Look the account up (includes/campaigns/identity.php)
            require_once '../includes/campaigns/bootstrap.php';
            $pick = signin_setup_account($pdo, $typed, $firstName);
            $user = $pick['user'];

            if ($pick['code'] === 'name_needed') {
                security_record_attempt($pdo, null, $typed, false, 'shared_phone_name_needed', 'first_time_setup');
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'NAME_NEEDED',
                    'message' => 'This number is on more than one account. Add your first name.',
                ]);
                exit;
            }

            if ($pick['code'] === 'none') {
                security_record_attempt($pdo, null, $typed, false, 'unknown_email', 'first_time_setup');
                echo json_encode(['status' => 'error', 'message' => 'We could not find an account for that email or phone.']);
                exit;
            }

            // 4. HARD BLOCK: accounts that already have a password must go
            //    through an authenticated change or an admin reset.
            if ($pick['code'] === 'has_password') {
                security_record_attempt($pdo, (int) $user['id'], $typed, false, 'already_has_password', 'first_time_setup');
                security_log(
                    $pdo,
                    'first_time_setup_blocked',
                    (int) $user['id'],
                    'Public setup attempt blocked: this account already has a password.',
                    null,
                    'Public form'
                );
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'This account already has a password, so it cannot be set from this public page. '
                               . 'Sign in and use My Profile → Security to change it, or ask a church administrator to reset it.',
                ]);
                exit;
            }

            // 5. Strength policy (shared with every other password entry point)
            $problems = security_password_problems($new_password, $user);
            if (!empty($problems)) {
                echo json_encode(['status' => 'error', 'message' => 'Password rejected: ' . implode(' ', $problems)]);
                exit;
            }

            // 6. Hash the new password securely
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

            // 7. Update the user record
            if (security_schema_ready($pdo)) {
                $pdo->prepare("
                    UPDATE users
                       SET password_hash = :pass,
                           password_changed_at = NOW(),
                           must_change_password = 0,
                           failed_login_count = 0,
                           locked_until = NULL
                     WHERE id = :id
                ")->execute(['pass' => $hashed_password, 'id' => $user['id']]);
            } else {
                $pdo->prepare("UPDATE users SET password_hash = :pass WHERE id = :id")
                    ->execute(['pass' => $hashed_password, 'id' => $user['id']]);
            }

            security_record_attempt($pdo, (int) $user['id'], $typed, true, null, 'first_time_setup');
            security_log(
                $pdo,
                'first_time_password_set',
                (int) $user['id'],
                'First password set through the public setup page.',
                null,
                'Public form'
            );

            echo json_encode(['status' => 'success', 'message' => 'Password created! Redirecting to login...']);
            break;

        // ==============================================================================
        // DEFAULT FALLBACK
        // ==============================================================================
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Security Setup API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error. Please contact technical support.']);
}
?>
