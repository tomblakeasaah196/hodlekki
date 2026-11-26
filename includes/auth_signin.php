<?php
// /includes/auth_signin.php
//
// The last steps of every sign-in, shared by the password (api/auth_api.php)
// and the passkey (api/passkey_api.php): is the account allowed in, fill the
// session, record it, "keep me signed in", and where to go next. Split out of
// auth_api.php unchanged (26 Nov 2026) so both ways in behave exactly alike.
// Needs includes/db.php (security helpers, permissions).

/**
 * @param string $loginId   what the attempt logs record (what was typed, or "passkey")
 * @param string $method    'password' | 'passkey'
 * @return array the JSON answer for the sign-in page
 */
function auth_finish_signin(PDO $pdo, array $user, string $loginId, bool $remember, bool $securityOn, string $method = 'password'): array
{
    // 9. The person is proven — now check the account is actually allowed in.
    //    (Deliberately after the proof, so the sign-in page can never be used
    //    to probe which accounts are suspended.)
    if ($securityOn) {
        $accountStatus = strtolower((string) ($user['account_status'] ?? 'active'));

        if ($accountStatus === 'suspended') {
            security_record_attempt($pdo, (int) $user['id'], $loginId, false, 'account_suspended');
            $reason = trim((string) ($user['status_reason'] ?? ''));
            return [
                'status'  => 'error',
                'message' => 'Your account is currently suspended.'
                           . ($reason !== '' ? ' Reason: ' . $reason . '.' : '')
                           . ' Please contact Information and Data Insights (IDI).',
            ];
        }

        if ($accountStatus === 'revoked' || $accountStatus === 'disabled') {
            security_record_attempt($pdo, (int) $user['id'], $loginId, false, 'account_revoked');
            return [
                'status'  => 'error',
                'message' => 'Access for this account has been revoked. Please contact Information and Data Insights (IDI).',
            ];
        }
    }

    // 10–13. Fetch the roles, rotate the session id (session-fixation guard)
    //        and fill the session (includes/auth_helpers.php).
    auth_start_session($pdo, $user, $securityOn);

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
        security_record_attempt($pdo, (int) $user['id'], $loginId, true, null);
        security_log($pdo, 'login_success', (int) $user['id'],
            ($method === 'passkey' ? 'Signed in with a passkey from ' : 'Signed in from ')
            . security_describe_device(security_user_agent()));

        $mustChange = (int) ($user['must_change_password'] ?? 0) === 1;
        $_SESSION['must_change_password'] = $mustChange;
    }

    // 14a. "Keep me signed in on this device". Unticked, a token this device
    //      held for anyone is retired: the device now belongs to whoever just
    //      proved who they are on it.
    if ($remember) {
        auth_remember_issue($pdo, (int) $user['id']);
    } else {
        auth_remember_forget_current($pdo);
    }

    // 14b. A password sign-in may offer a passkey on the next page
    //      (includes/header.php shows the card once; the browser decides
    //      whether this device can make one).
    if ($method === 'password') {
        $_SESSION['passkey_offer'] = true;
    }

    // 15. A forced password change short-circuits everything else.
    if ($mustChange) {
        return [
            'status'   => 'success',
            'message'  => 'Password change required. Redirecting...',
            'redirect' => '/auth/change_password',
        ];
    }

    // 16. Where to land: the dashboard when the permission matrix gives you
    //     more than your own alerts on it (church-wide, or a department you
    //     lead); else the Portal.
    $landing = perm_resolve($pdo, (int) $user['id']);
    $hasDashboard = $landing['breakglass']
        || perm_scope_beyond_own(perm_ctx_scope($landing, 'dashboard', 'view'));
    $target = $hasDashboard ? '/' : '/modules/member_portal/';

    // A "return to" path stored by auth/login.php?next=… wins: the page a
    // tapped notification or a crew console link was opening (guide §21.4).
    // It is checked again here (auth_safe_next) and consumed once. A forced
    // password change returns earlier and leaves it in place, so
    // change_password.php can send the member on afterwards.
    $returning = false;
    $storedNext = auth_safe_next($_SESSION['post_login_next'] ?? null);
    unset($_SESSION['post_login_next']);
    if ($storedNext !== null) {
        $target = $storedNext;
        $returning = true;
    }

    // 17. `returning` tells the sign-in page to put back the #fragment the
    //     server never sees.
    return [
        'status'    => 'success',
        'message'   => 'Login successful! Setting up workspace...',
        'redirect'  => $target,
        'returning' => $returning,
    ];
}
