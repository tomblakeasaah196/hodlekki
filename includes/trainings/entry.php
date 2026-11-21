<?php
// /includes/trainings/entry.php
//
// The QR entry to a standalone test (owner, 21 Nov 2026: "very lean and seamless… straight to the point"):
//
//   phone → "Welcome, Tom Blake" → their password, or "Set a password" if they never had one
//         → their email, only if we have none → signed in → the test (My trainings' test page).
//   Forgot the password → a six-digit code to their CONFIRMED email (includes/auth_codes.php) → a new password → in.
//   No confirmed email to send it to, a number HOD does not know, or an account that is not open → the church office.
//   After the test, the result asks them to confirm their email with a code (trainings_entry_email_prompt()).
//
// Why a reset needs the code: resetting on a phone number alone would let anyone who knows a member's number take
// over the account (owner chose "Email code first"). A FIRST password is set on the number alone, as the first-time
// set-up page already allows, but never for an account with a staff role (those go to the office).
//
// Page: test.php (public, strict CSP), script assets/ui/js/pages/test-entry.js, endpoint api/test_entry_api.php
// (CSRF checked). State between steps: $_SESSION['test_entry'] (the test, the accounts on the number, the one chosen,
// the number typed, when; TRAININGS_ENTRY_TTL seconds). Needs includes/campaigns/bootstrap.php (signin_*, email_*),
// includes/auth_helpers.php and includes/security_helpers.php. Guide: docs/EXAMS.md §9. Tests: tests/trainings/run.php,
// "QR entry".

require_once dirname(__DIR__) . '/auth_codes.php';

const TRAININGS_ENTRY_TTL = 1200;            // 20 minutes from the number to the last step
const TRAININGS_ENTRY_LOOKUPS_PER_IP = 20;   // numbers HOD does not know, per IP per 15 minutes
const TRAININGS_ENTRY_OFFICE = 'Please see the church office.';

/** The published standalone test behind an item id, or null. */
function trainings_entry_test(PDO $pdo, int $itemId): ?array
{
    $item = function_exists('exam_item') ? exam_item($pdo, $itemId) : null;
    if ($item === null || ($item['training_kind'] ?? '') !== 'test' || ($item['training_status'] ?? '') !== 'published') {
        return null;
    }
    return $item;
}

/** A users row, or null. */
function trainings_entry_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function trainings_entry_name(array $u): string
{
    return trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')) ?: 'friend';
}

/** Is the account open (not suspended, revoked or disabled)? */
function trainings_entry_open_account(array $u): bool
{
    return !in_array(strtolower((string) ($u['account_status'] ?? 'active')), ['suspended', 'revoked', 'disabled'], true);
}

/** Does the account hold a staff role (user_roles)? Its first password is set at the office, never from a QR code. */
function trainings_entry_is_staff(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** The steps' memory, or null when there is none or it is too old. */
function trainings_entry_state(): ?array
{
    $s = $_SESSION['test_entry'] ?? null;
    if (!is_array($s) || (int) ($s['at'] ?? 0) < time() - TRAININGS_ENTRY_TTL) {
        return null;
    }
    return $s;
}

/** What comes after the number for one account: password | set_password | office. */
function trainings_entry_step_for(PDO $pdo, array $u): array
{
    if (!trainings_entry_open_account($u)) {
        return ['step' => 'office', 'message' => TRAININGS_ENTRY_OFFICE];
    }
    $name = trainings_entry_name($u);
    if (!empty($u['password_hash'])) {
        return ['step' => 'password', 'name' => $name];
    }
    if (trainings_entry_is_staff($pdo, (int) $u['id'])) {
        return ['step' => 'office', 'name' => $name, 'message' => 'Set your first password at the church office.'];
    }
    return ['step' => 'set_password', 'name' => $name];
}

/**
 * After signing in: do we still need their email? Only when HOD has none for them (none confirmed, none waiting,
 * none on file) and they have not said "I don't have one" lately. An address on file starts waiting to be confirmed
 * here (no email sent), so the app's email prompt lets them through to the test and the result asks for the code.
 */
function trainings_entry_needs_email(PDO $pdo, int $userId): bool
{
    if (!email_identity_ready($pdo)) {
        return false;
    }
    $st = email_address_state($pdo, $userId);
    if ($st['confirmed'] !== null || $st['pending'] !== null) {
        return false;
    }
    if ($st['on_file'] !== null) {
        email_capture_start($pdo, $userId, (string) $st['on_file'], 'signin_prompt', $userId, ['send' => false]);
        email_prompt_forget();
        return false;
    }
    return email_prompt_state($pdo, $userId) === 'required';
}

/** Where a signed-in person goes next: the email step, or the test. */
function trainings_entry_next(PDO $pdo, int $userId, int $itemId): array
{
    return trainings_entry_needs_email($pdo, $userId)
        ? ['status' => 'success', 'step' => 'email']
        : ['status' => 'success', 'step' => 'go', 'go' => exam_url_take($itemId)];
}

/**
 * Sign them in as a password sign-in does (api/auth_api.php steps 10-14): the session, the Security Centre's session
 * row and sign-in record, "keep me signed in". $how names the way in for the records.
 */
function trainings_entry_sign_in(PDO $pdo, array $user, string $how): void
{
    $securityOn = function_exists('security_schema_ready') && security_schema_ready($pdo);
    auth_start_session($pdo, $user, $securityOn);
    if ($securityOn) {
        $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
            ->execute([security_client_ip(), $user['id']]);
        security_register_session($pdo, (int) $user['id']);
        security_record_attempt($pdo, (int) $user['id'], (string) ($_SESSION['test_entry']['phone'] ?? ''), true, null, 'test_qr');
        security_log($pdo, 'login_success', (int) $user['id'], 'Signed in from a test QR code (' . $how . ') on ' . security_describe_device(security_user_agent()));
        $_SESSION['must_change_password'] = (int) ($user['must_change_password'] ?? 0) === 1;
    }
    if (function_exists('auth_remember_issue')) {
        auth_remember_issue($pdo, (int) $user['id']);
    }
}

/** Numbers HOD did not know from this IP in the last 15 minutes. */
function trainings_entry_throttled(PDO $pdo, string $ip): bool
{
    if ($ip === '' || !function_exists('security_schema_ready') || !security_schema_ready($pdo)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE context = 'test_entry' AND was_successful = 0 AND ip_address = ? AND created_at >= ?");
        $stmt->execute([$ip, campaigns_sql_dt(campaigns_now()->modify('-15 minutes'))]);
        return (int) $stmt->fetchColumn() >= TRAININGS_ENTRY_LOOKUPS_PER_IP;
    } catch (Throwable $e) {
        return false;
    }
}

/** Set a password (the one policy), clear any lock. */
function trainings_entry_set_password(PDO $pdo, int $userId, string $password): void
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if (function_exists('security_schema_ready') && security_schema_ready($pdo)) {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, failed_login_count = 0, locked_until = NULL WHERE id = ?')
            ->execute([$hash, $userId]);
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    }
}

/** The two passwords and the policy. Null when fine, else the error reply. */
function trainings_entry_password_problem(string $password, string $confirm, array $user): ?array
{
    if ($password === '') {
        return ['status' => 'error', 'message' => 'Choose a password.', 'field' => 'password'];
    }
    if ($password !== $confirm) {
        return ['status' => 'error', 'message' => 'The two passwords differ.', 'field' => 'confirm'];
    }
    $problems = function_exists('security_password_problems') ? security_password_problems($password, $user) : [];
    return $problems !== [] ? ['status' => 'error', 'message' => (string) $problems[0], 'field' => 'password', 'problems' => $problems] : null;
}

/**
 * One step. $in: the request; $ip: the client's address; $signIn: how to sign someone in (tests pass their own).
 * @return array the JSON reply: status, and step (choose | password | set_password | email | code | office | go)
 */
function trainings_entry_action(PDO $pdo, string $do, array $in, string $ip, ?callable $signIn = null): array
{
    $signIn ??= static function (array $user, string $how) use ($pdo): void {
        trainings_entry_sign_in($pdo, $user, $how);
    };
    $fail = static fn (string $message, ?string $code = null, ?string $field = null): array =>
        ['status' => 'error', 'message' => $message] + ($code ? ['code' => $code] : []) + ($field ? ['field' => $field] : []);
    $expired = $fail('Start again with your phone number.', 'EXPIRED');

    // ---- After the test (signed in): confirm the email with a code. ----
    if ($do === 'confirm_send' || $do === 'confirm_check') {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $user = $uid > 0 ? trainings_entry_user($pdo, $uid) : null;
        if ($user === null) {
            return $fail('Please sign in again.', 'SIGNED_OUT');
        }
        return $do === 'confirm_send' ? trainings_entry_confirm_send($pdo, $user, $in, $ip) : trainings_entry_confirm_check($pdo, $user, (string) ($in['code'] ?? ''));
    }

    // ---- The way in. ----
    if ($do === 'lookup') {
        $itemId = (int) ($in['t'] ?? 0);
        if (trainings_entry_test($pdo, $itemId) === null) {
            return $fail('This test is not open.', 'CLOSED');
        }
        $phone = trim((string) ($in['phone'] ?? ''));
        if (!signin_is_phone($phone)) {
            return $fail('Enter your phone number.', 'INVALID', 'phone');
        }
        if (trainings_entry_throttled($pdo, $ip)) {
            return $fail('Too many tries. Wait a few minutes.', 'THROTTLED');
        }
        $people = signin_phone_users($pdo, $phone);
        if ($people === []) {
            if (function_exists('security_record_attempt')) {
                security_record_attempt($pdo, null, $phone, false, 'unknown_number', 'test_entry');
            }
            unset($_SESSION['test_entry']);
            return ['status' => 'success', 'step' => 'office', 'message' => 'We could not find this number. ' . TRAININGS_ENTRY_OFFICE];
        }
        $_SESSION['test_entry'] = ['item' => $itemId, 'uids' => array_map(static fn ($u) => (int) $u['id'], $people),
                                   'uid' => null, 'phone' => $phone, 'at' => time()];
        if (count($people) === 1) {
            $_SESSION['test_entry']['uid'] = (int) $people[0]['id'];
            return ['status' => 'success'] + trainings_entry_step_for($pdo, $people[0]);
        }
        return ['status' => 'success', 'step' => 'choose', 'people' => array_map(static fn ($u, $i) => ['i' => $i,
            'name' => trim((string) $u['first_name'] . ' ' . mb_substr((string) $u['last_name'], 0, 1) . ((string) $u['last_name'] !== '' ? '.' : ''))],
            $people, array_keys($people))];
    }

    $s = trainings_entry_state();
    if ($s === null) {
        return $expired;
    }
    $itemId = (int) $s['item'];

    if ($do === 'choose') {
        $i = (int) ($in['i'] ?? -1);
        $uid = $s['uids'][$i] ?? null;
        $user = $uid !== null ? trainings_entry_user($pdo, (int) $uid) : null;
        if ($user === null) {
            return $expired;
        }
        $_SESSION['test_entry']['uid'] = (int) $uid;
        return ['status' => 'success'] + trainings_entry_step_for($pdo, $user);
    }

    $user = !empty($s['uid']) ? trainings_entry_user($pdo, (int) $s['uid']) : null;
    if ($user === null) {
        return $expired;
    }
    $uid = (int) $user['id'];

    switch ($do) {
        case 'signin':
            $password = (string) ($in['password'] ?? '');
            if ($password === '') {
                return $fail('Enter your password.', 'INVALID', 'password');
            }
            $security = function_exists('security_schema_ready') && security_schema_ready($pdo);
            $check = signin_check_password($pdo, [$user], $password, (string) $s['phone'], $security);
            if (empty($check['ok'])) {
                return $fail((string) ($check['message'] ?? 'Wrong password.') === SIGNIN_WRONG ? 'Wrong password. Try again.' : (string) $check['message'], 'WRONG', 'password');
            }
            if (!trainings_entry_open_account($check['user'])) {
                return ['status' => 'success', 'step' => 'office', 'message' => TRAININGS_ENTRY_OFFICE];
            }
            $signIn($check['user'], 'password');
            return trainings_entry_next($pdo, $uid, $itemId);

        case 'set_password':
            $step = trainings_entry_step_for($pdo, $user);
            if ($step['step'] !== 'set_password') {
                return ['status' => 'success'] + $step;   // a password was set meanwhile, or the office
            }
            if ($bad = trainings_entry_password_problem((string) ($in['password'] ?? ''), (string) ($in['confirm'] ?? ''), $user)) {
                return $bad;
            }
            // Only an account that still has no password: two presses at once cannot both set one.
            $claim = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND (password_hash IS NULL OR password_hash = '')");
            $claim->execute([password_hash((string) $in['password'], PASSWORD_BCRYPT), $uid]);
            if ($claim->rowCount() !== 1) {
                return ['status' => 'success', 'step' => 'password', 'name' => trainings_entry_name($user)];
            }
            trainings_entry_set_password($pdo, $uid, (string) $in['password']);
            if (function_exists('security_log')) {
                security_log($pdo, 'password_first_set', $uid, 'First password set from a test QR code.', $uid, trainings_entry_name($user));
            }
            $signIn(trainings_entry_user($pdo, $uid) ?? $user, 'first password');
            return trainings_entry_next($pdo, $uid, $itemId);

        case 'email':
        case 'email_skip':
            if ((int) ($_SESSION['user_id'] ?? 0) !== $uid) {
                return $expired;
            }
            if ($do === 'email_skip') {
                email_prompt_skip($pdo, $uid);
            } else {
                $r = email_capture_start($pdo, $uid, (string) ($in['email'] ?? ''), 'signin_prompt', $uid, ['send' => false]);
                if (empty($r['ok'])) {
                    return $fail((string) ($r['message'] ?? 'Check the email address.'), (string) ($r['code'] ?? 'INVALID'), 'email');
                }
            }
            email_prompt_forget();
            return ['status' => 'success', 'step' => 'go', 'go' => exam_url_take($itemId)];

        case 'forgot':
            $to = email_identity_ready($pdo) ? member_confirmed_email($pdo, $uid) : null;
            if ($to === null) {
                return ['status' => 'success', 'step' => 'office', 'message' => 'The church office can reset your password.'];
            }
            $r = auth_code_send($pdo, $user, 'reset', $to, $ip);
            return !empty($r['ok']) ? ['status' => 'success', 'step' => 'code', 'to' => $r['to']] : $fail((string) $r['message'], (string) $r['code']);

        case 'reset':
            if ($bad = trainings_entry_password_problem((string) ($in['password'] ?? ''), (string) ($in['confirm'] ?? ''), $user)) {
                return $bad;
            }
            $check = auth_code_check($pdo, $uid, 'reset', (string) ($in['code'] ?? ''));
            if (empty($check['ok'])) {
                return $fail((string) $check['message'], (string) $check['code'], 'code');
            }
            if (!trainings_entry_open_account($user)) {
                return ['status' => 'success', 'step' => 'office', 'message' => TRAININGS_ENTRY_OFFICE];
            }
            trainings_entry_set_password($pdo, $uid, (string) $in['password']);
            if (function_exists('security_revoke_sessions')) {
                security_revoke_sessions($pdo, $uid, $uid, 'Password reset by email code');
            }
            if (function_exists('security_log')) {
                security_log($pdo, 'password_reset_by_code', $uid, 'Password changed with an emailed code (test QR); every other session signed out.', $uid, trainings_entry_name($user));
            }
            email_send_doc($pdo, email_password_changed_doc(), $user + ['email' => $check['email']], [
                'lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_changed', 'to_email' => $check['email'], 'now' => true,
            ]);
            $signIn(trainings_entry_user($pdo, $uid) ?? $user, 'password reset');
            return trainings_entry_next($pdo, $uid, $itemId);
    }
    return $fail('Unknown action.');
}

/**
 * The result's card after a test: null when their email is confirmed (or they said they have none);
 * ['state' => 'confirm', 'to' => masked] when one waits; ['state' => 'add'] when HOD has none.
 */
function trainings_entry_email_prompt(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !email_identity_ready($pdo) || !auth_codes_ready($pdo)) {
        return null;
    }
    $st = email_address_state($pdo, $userId);
    if ($st['confirmed'] !== null) {
        return null;
    }
    $waiting = $st['pending'] ?? $st['on_file'];
    if ($waiting !== null) {
        return ['state' => 'confirm', 'to' => email_mask((string) $waiting)];
    }
    return email_prompt_state($pdo, $userId) === 'none' ? null : ['state' => 'add'];
}

/** Send the confirm code: to the address typed now, else the one waiting, else the one on file. */
function trainings_entry_confirm_send(PDO $pdo, array $user, array $in, string $ip): array
{
    $uid = (int) $user['id'];
    if (!email_identity_ready($pdo)) {
        return ['status' => 'error', 'message' => 'Email is being set up. Try again later.'];
    }
    $st = email_address_state($pdo, $uid);
    if ($st['confirmed'] !== null && trim((string) ($in['email'] ?? '')) === '') {
        return ['status' => 'success', 'done' => true, 'message' => 'Your email is confirmed.'];
    }
    $typed = trim((string) ($in['email'] ?? ''));
    $to = $typed !== '' ? $typed : ($st['pending'] ?? $st['on_file']);
    if ($to === null || $to === '') {
        return ['status' => 'error', 'message' => 'Enter your email.', 'field' => 'email'];
    }
    if ($typed !== '' || $st['pending'] === null || email_normalize($to) !== $st['pending']) {
        $r = email_capture_start($pdo, $uid, $to, 'signin_prompt', $uid, ['send' => false]);
        if (empty($r['ok'])) {
            return ['status' => 'error', 'message' => (string) ($r['message'] ?? 'Check the email address.'), 'field' => 'email'];
        }
        if (!empty($r['already_confirmed'])) {
            return ['status' => 'success', 'done' => true, 'message' => 'Your email is confirmed.'];
        }
        $to = (string) $r['email'];
        email_prompt_forget();
    }
    $r = auth_code_send($pdo, $user, 'confirm', email_normalize($to), $ip);
    return !empty($r['ok']) ? ['status' => 'success', 'to' => $r['to']] : ['status' => 'error', 'message' => (string) $r['message'], 'code' => $r['code']];
}

/** The code is right: confirm the address it went to (through the email identity flow, as its link would). */
function trainings_entry_confirm_check(PDO $pdo, array $user, string $code): array
{
    $uid = (int) $user['id'];
    $check = auth_code_check($pdo, $uid, 'confirm', $code);
    if (empty($check['ok'])) {
        return ['status' => 'error', 'message' => (string) $check['message'], 'code' => $check['code'], 'field' => 'code'];
    }
    $stmt = $pdo->prepare("SELECT id FROM email_addresses WHERE user_id = ? AND email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid, email_normalize((string) $check['email'])]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        return email_address_state($pdo, $uid)['confirmed'] === email_normalize((string) $check['email'])
            ? ['status' => 'success', 'done' => true, 'message' => 'Your email is confirmed.']
            : ['status' => 'error', 'message' => 'Ask for a new code.', 'code' => 'NONE'];
    }
    // A fresh link token for that row, used at once: the same confirmation as the emailed link (includes/campaigns/addresses.php).
    $token = campaigns_token(32);
    $pdo->prepare('UPDATE email_addresses SET token_hash = ?, token_expires_at = ? WHERE id = ?')
        ->execute([hash('sha256', $token), campaigns_sql_dt(campaigns_now()->modify('+1 hour')), (int) $id]);
    $r = email_capture_confirm($pdo, $token);
    email_prompt_forget();
    return !empty($r['ok']) ? ['status' => 'success', 'done' => true, 'message' => 'Your email is confirmed.']
        : ['status' => 'error', 'message' => $r['code'] === 'REFUSED' ? 'That email is in use. Ask the church office.' : 'Ask for a new code.', 'code' => (string) $r['code']];
}
