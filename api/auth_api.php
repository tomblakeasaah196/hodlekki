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

// 4. Capture and sanitize inputs
$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$password = $_POST['password'] ?? '';

// 5. Basic validation
if (empty($email) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide both email and password.']);
    exit;
}

try {
    // 6. Fetch user from the database.
    //    SELECT * (rather than a fixed column list) so this endpoint keeps
    //    working if it is deployed before db/migrate.php adds the new
    //    account-security columns.
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    $securityOn = security_schema_ready($pdo);

    // 7. Temporary lockout from repeated failed attempts.
    if ($user && $securityOn && !empty($user['locked_until'])) {
        $lockedUntil = strtotime((string) $user['locked_until']);
        if ($lockedUntil !== false && $lockedUntil > time()) {
            $minutes = max(1, (int) ceil(($lockedUntil - time()) / 60));
            security_record_attempt($pdo, (int) $user['id'], $email, false, 'account_locked');
            echo json_encode([
                'status'  => 'error',
                'message' => "Too many failed sign-in attempts. This account is locked for another {$minutes} minute"
                           . ($minutes === 1 ? '' : 's') . '. Contact an administrator if you need it opened sooner.',
            ]);
            exit;
        }
    }

    // 8. Verify user exists AND password is correct
    $passwordOk = $user && !empty($user['password_hash']) && password_verify($password, $user['password_hash']);

    if (!$passwordOk) {
        if ($user && $securityOn) {
            // Count this failure and lock the account once the threshold trips.
            $failures = (int) ($user['failed_login_count'] ?? 0) + 1;

            if ($failures >= SECURITY_MAX_FAILED_LOGINS) {
                $pdo->prepare("
                    UPDATE users
                       SET failed_login_count = ?,
                           locked_until = (NOW() + INTERVAL " . SECURITY_LOCK_MINUTES . " MINUTE)
                     WHERE id = ?
                ")->execute([$failures, $user['id']]);

                security_record_attempt($pdo, (int) $user['id'], $email, false, 'bad_password_locked');
                security_log(
                    $pdo,
                    'account_auto_locked',
                    (int) $user['id'],
                    "Account locked automatically after {$failures} consecutive failed sign-in attempts.",
                    null,
                    'System'
                );

                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Too many failed sign-in attempts. This account has been locked for '
                               . SECURITY_LOCK_MINUTES . ' minutes for your protection.',
                ]);
                exit;
            }

            $pdo->prepare("UPDATE users SET failed_login_count = ? WHERE id = ?")
                ->execute([$failures, $user['id']]);
            security_record_attempt($pdo, (int) $user['id'], $email, false, 'bad_password');

            $left = SECURITY_MAX_FAILED_LOGINS - $failures;
            echo json_encode([
                'status'  => 'error',
                'message' => 'Invalid email or password.'
                           . ($left <= 2 ? " {$left} attempt" . ($left === 1 ? '' : 's') . ' left before this account is locked.' : ''),
            ]);
            exit;
        }

        // Unknown email (or pre-migration): generic error, no enumeration.
        security_record_attempt($pdo, null, $email, false, 'unknown_email');
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
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
                           . ' Please contact the church office.',
            ]);
            exit;
        }

        if ($accountStatus === 'revoked' || $accountStatus === 'disabled') {
            security_record_attempt($pdo, (int) $user['id'], $email, false, 'account_revoked');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Access for this account has been revoked. Please contact the church office.',
            ]);
            exit;
        }
    }

    // 10. Fetch all roles assigned to this user.
    //     Frozen role rows are excluded: a revoked-then-reinstated account comes
    //     back with zero clearance until an admin restores its roles.
    $roleSql = "
        SELECT r.id, r.role_name
        FROM roles r
        JOIN user_roles ur ON r.id = ur.role_id
        WHERE ur.user_id = :user_id
    ";
    if ($securityOn) {
        // The migration guarantees user_roles.is_frozen exists; before it has
        // run we keep the original (unfiltered) behaviour so login never breaks
        // in the window between rsync and `php db/migrate.php`.
        $roleSql .= " AND COALESCE(ur.is_frozen, 0) = 0";
    }
    $roleStmt = $pdo->prepare($roleSql);
    $roleStmt->execute(['user_id' => $user['id']]);
    $roles = $roleStmt->fetchAll();

    // 11. Rotate the session id before storing anything (session-fixation guard)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    // 12. Store essential user data in the secure session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['picture_path'] = $user['picture_path'];
    $_SESSION['auth_started_at'] = time();

    // 13. Handle RBAC (Role-Based Access Control) setup
    if (count($roles) > 0) {
        $_SESSION['roles'] = $roles; // Store all roles in case they have multiple
        $_SESSION['active_role'] = $roles[0]['role_name']; // Default to primary
        $_SESSION['active_role_id'] = $roles[0]['id'];
    } else {
        $_SESSION['roles'] = [];
        $_SESSION['active_role'] = 'Member'; // Fallback
        $_SESSION['active_role_id'] = null;
    }

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

    // 15. A forced password change short-circuits everything else.
    if ($mustChange) {
        echo json_encode([
            'status'   => 'success',
            'message'  => 'Password change required. Redirecting...',
            'redirect' => '/auth/change_password.php',
        ]);
        exit;
    }

    // 16. Determine Redirect URL (Dashboard Access Matrix)
    $has_dashboard_access = false;
    $dashboard_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'];

    // Check if they have a qualifying role
    if (!empty($roles)) {
        foreach ($roles as $r) {
            if (in_array($r['role_name'], $dashboard_roles)) {
                $has_dashboard_access = true;
                break;
            }
        }
    }

    // Check if they are in the IDI department (ID: 1)
    if (!$has_dashboard_access) {
        $deptCheck = $pdo->prepare("SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = 1 AND is_active = 1");
        $deptCheck->execute([$user['id']]);
        if ($deptCheck->fetch()) {
            $has_dashboard_access = true;
        }
    }

    // Set the final destination based on their access
    $target_redirect = $has_dashboard_access ? '/index.php' : '/modules/member_portal/index.php';

    // A "return to" path stored by auth/login.php?next=… wins (guide §21.4).
    // It was validated there as a same-origin /e/ or /modules/ path; it is
    // consumed once, and never applies when a password change is forced
    // (that branch returns earlier).
    if (!empty($_SESSION['post_login_next'])) {
        $target_redirect = (string) $_SESSION['post_login_next'];
        unset($_SESSION['post_login_next']);
    }

    // 17. Return success payload with dynamic redirect
    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful! Setting up workspace...',
        'redirect' => $target_redirect
    ]);

} catch (PDOException $e) {
    // Log the actual error internally, but return a clean message to the user
    error_log("Login Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again later.']);
}
?>
