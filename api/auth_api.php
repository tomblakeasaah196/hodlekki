<?php
// /api/auth_api.php

// 1. Include the DB connection (which also starts the session and loads the
//    account-security helpers via includes/security_helpers.php)
require_once '../includes/db.php';

// 2. Tell the browser we are returning JSON
header('Content-Type: application/json');

// 3. Ensure this is only accessed via a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// 4. Capture the inputs. What was typed is a personal email, a church
//    sign-in name or a phone number (owner's decision, 13 Nov 2026).
$typed = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
$password = (string) ($_POST['password'] ?? '');

// 5. Basic validation
if ($typed === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'Enter your email or phone, and your password.']);
    exit;
}
$email = $typed; // what the attempt logs record

try {
    // 6. Who might this be? A confirmed personal email first, then users.email
    //    (the church sign-in name, or a retired one), or every account on a
    //    phone number. docs/EMAIL.md, "Sign-in and reset". Rows are SELECT *
    //    so this keeps working before db/migrate.php adds the security columns.
    require_once '../includes/campaigns/bootstrap.php';
    $found = signin_candidates($pdo, $typed);

    $securityOn = security_schema_ready($pdo);

    // 7–8. Locked accounts are not tried; the password decides among the rest
    //      (a shared number opens the account whose password it is); a wrong
    //      one counts towards each account's lockout.
    $check = signin_check_password($pdo, $found['users'], $password, $typed, $securityOn);
    if (!$check['ok']) {
        echo json_encode(['status' => 'error', 'message' => $check['message']]);
        exit;
    }
    $user = $check['user'];

    // 8b. The old church sign-in name of someone who has moved to their
    //     personal email (owner's decision: names work until each person has
    //     moved). Only said after the right password, so the masked address is
    //     never shown to someone who merely knows a sign-in name.
    if (!empty($found['moved_to'])) {
        security_record_attempt($pdo, (int) $user['id'], $email, false, 'signin_name_moved');
        echo json_encode([
            'status'  => 'error',
            'code'    => 'MOVED',
            'message' => 'You now sign in with your personal email (' . $found['moved_to'] . ') or your phone, and the same password.',
        ]);
        exit;
    }

    // 9–17. Allowed in? Session, records, "keep me signed in", where to go:
    //       shared with the passkey sign-in (includes/auth_signin.php).
    require_once '../includes/auth_signin.php';
    echo json_encode(auth_finish_signin($pdo, $user, $email, !empty($_POST['remember']), $securityOn, 'password'));
} catch (PDOException $e) {
    // Log the actual error internally, but return a clean message to the user
    error_log("Login Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again later.']);
}
?>
