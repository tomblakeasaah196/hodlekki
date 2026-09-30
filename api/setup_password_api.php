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
        // ACTION 1: LOOKUP SYSTEM EMAIL VIA PHONE & FIRST NAME
        //           Only reveals accounts that have never set a password.
        // ==============================================================================
        case 'lookup_email':
            $fname = trim($_POST['first_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($fname) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'Please provide both your First Name and Phone Number.']);
                exit;
            }

            // Clean the phone number of spaces, brackets, or dashes for DB matching
            $cleanPhone = preg_replace('/[\s\-\(\)]/', '', $phone);

            // Using LIKE for phone variations, and checking if the entered name matches EITHER their first or last name
            $stmt = $pdo->prepare("
                SELECT id, email, password_hash
                  FROM users
                 WHERE phone LIKE ? AND (first_name = ? OR last_name = ?)
                 LIMIT 1
            ");

            // Note: We pass $fname twice because it checks against both columns
            $stmt->execute(['%' . ltrim($cleanPhone, '0+') . '%', $fname, $fname]);
            $user = $stmt->fetch();

            if (!$user || empty($user['email'])) {
                security_record_attempt($pdo, null, $fname . ' / ' . $phone, false, 'lookup_no_match', 'first_time_setup');
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'We could not find an account matching that name and phone number.',
                ]);
                exit;
            }

            // Account already has a password — this public flow must not touch it.
            if (!empty($user['password_hash'])) {
                security_record_attempt($pdo, (int) $user['id'], (string) $user['email'], false, 'already_has_password', 'first_time_setup');
                security_log(
                    $pdo,
                    'first_time_setup_blocked',
                    (int) $user['id'],
                    'Public setup lookup blocked: this account already has a password.',
                    null,
                    'Public form'
                );
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'This account already has a password. Sign in and use My Profile → Security to change it, '
                               . 'or ask a church administrator to reset it for you.',
                ]);
                exit;
            }

            echo json_encode([
                'status' => 'success',
                'email'  => $user['email'],
            ]);
            break;

        // ==============================================================================
        // ACTION 2: SET THE VERY FIRST PASSWORD ON AN ACCOUNT
        // ==============================================================================
        case 'setup_password':
            $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // 1. Basic empty checks
            if (empty($email) || empty($new_password) || empty($confirm_password)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
                exit;
            }

            // 2. Password Match Check
            if (!hash_equals($new_password, $confirm_password)) {
                echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
                exit;
            }

            // 3. Look the account up
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, password_hash FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                security_record_attempt($pdo, null, $email, false, 'unknown_email', 'first_time_setup');
                echo json_encode(['status' => 'error', 'message' => 'Email address not found in our records.']);
                exit;
            }

            // 4. HARD BLOCK: accounts that already have a password must go
            //    through an authenticated change or an admin reset.
            if (!empty($user['password_hash'])) {
                security_record_attempt($pdo, (int) $user['id'], $email, false, 'already_has_password', 'first_time_setup');
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

            security_record_attempt($pdo, (int) $user['id'], $email, true, null, 'first_time_setup');
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
