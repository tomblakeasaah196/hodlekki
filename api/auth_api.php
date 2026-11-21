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

    // 9. Password is correct — now check the account is actually allowed in.
    //    (Deliberately after password verification so the login form can never
    //    be used to probe which accounts are suspended.)
    if ($securityOn) {
        $accountStatus = strtolower((string) ($user['account_status'] ?? 'active'));

        if ($accountStatus === 'suspended') {
            security_record_attempt($pdo, (int) $user['id'], $email, false, 'account_suspended');
            $reason = trim((string) ($user['status_reason'] ?? ''));
            echo json_encode([
                'status'  => 'error',
                'message' => 'Your account is currently suspended.'
                           . ($reason !== '' ? ' Reason: ' . $reason . '.' : '')
                           . ' Please contact Information and Data Insights (IDI).',
            ]);
            exit;
        }

        if ($accountStatus === 'revoked' || $accountStatus === 'disabled') {
            security_record_attempt($pdo, (int) $user['id'], $email, false, 'account_revoked');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Access for this account has been revoked. Please contact Information and Data Insights (IDI).',
            ]);
            exit;
        }
    }

    // 10–13. Fetch the roles, rotate the session id (session-fixation guard)
    //        and fill the session. Shared with "stay signed in" so a resumed
    //        session is built exactly like this one (includes/auth_helpers.php).
    //        Frozen role rows are excluded once the security migration has run:
    //        a revoked-then-reinstated account comes back with zero clearance
    //        until an admin restores its roles. Before it has run we keep the
    //        unfiltered behaviour so login never breaks in the window between
    //        rsync and `php db/migrate.php`.
    $roles = auth_start_session($pdo, $user, $securityOn);

    // 14. Record the successful sign-in and register this browser session so it
    //     shows up in (and can be killed from) the admin Security Centre.
    $mustChange = false;
    if ($securityOn) {
        $pdo->prepare("
            UPDATE users
               SET failed_login_count = 0,
                   locked_until = NULL,
                   last_login_at = NOW(),
                   last_login_ip = ?
             WHERE id = ?
        ")->execute([security_client_ip(), $user['id']]);

        security_register_session($pdo, (int) $user['id']);
        security_record_attempt($pdo, (int) $user['id'], $email, true, null);
        security_log($pdo, 'login_success', (int) $user['id'], 'Signed in from ' . security_describe_device(security_user_agent()));

        $mustChange = (int) ($user['must_change_password'] ?? 0) === 1;
        $_SESSION['must_change_password'] = $mustChange;
    }

    // 14a. "Keep me signed in on this device" (ticked by default). Unticked, a
    //      token this device held for anyone is retired: the device now
    //      belongs to whoever just proved their password on it.
    if (!empty($_POST['remember'])) {
        auth_remember_issue($pdo, (int) $user['id']);
    } else {
        auth_remember_forget_current($pdo);
    }

    // 15. A forced password change short-circuits everything else.
    if ($mustChange) {
        echo json_encode([
            'status'   => 'success',
            'message'  => 'Password change required. Redirecting...',
            'redirect' => '/auth/change_password',
        ]);
        exit;
    }

    // 16. Determine Redirect URL (Dashboard Access Matrix)
    // The dashboard when the permission matrix gives you more than your own
    // alerts on it (church-wide, or a department you lead); else the Portal.
    $landing = perm_resolve($pdo, (int) $user['id']);
    $has_dashboard_access = $landing['breakglass']
        || perm_scope_beyond_own(perm_ctx_scope($landing, 'dashboard', 'view'));

    // Set the final destination based on their access
    $target_redirect = $has_dashboard_access ? '/' : '/modules/member_portal/';

    // A "return to" path stored by auth/login.php?next=… wins: the page a
    // tapped notification or a crew console link was opening (guide §21.4).
    // It is checked again here (auth_safe_next) and consumed once. A forced
    // password change returns earlier and leaves it in place, so
    // change_password.php can send the member on afterwards.
    $returning = false;
    $stored_next = auth_safe_next($_SESSION['post_login_next'] ?? null);
    unset($_SESSION['post_login_next']);
    if ($stored_next !== null) {
        $target_redirect = $stored_next;
        $returning = true;
    }

    // 17. Return success payload with dynamic redirect. `returning` tells the
    //     login page to put back the #fragment the server never sees.
    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful! Setting up workspace...',
        'redirect' => $target_redirect,
        'returning' => $returning
    ]);

} catch (PDOException $e) {
    // Log the actual error internally, but return a clean message to the user
    error_log("Login Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again later.']);
}
?>
