<?php
// /includes/campaigns/identity.php
//
// Email as identity (owner's decisions 1 and 2, 11 Nov 2026):
//   - the REQUIRED prompt after sign-in: everyone adds a personal email, or
//     confirms the one on file; while a link is pending they get in with a
//     banner for 7 days, then the prompt blocks again; "I don't have an email"
//     is recorded and asked again in 90 days;
//   - sign-in with the confirmed personal email; the old church sign-in name
//     keeps working until the person has moved (confirmed an address), then
//     it explains where they sign in now;
//   - "forgot password" sends a reset link to the confirmed personal email
//     only. Account mail: no consent needed, first in the queue.
//
// Every credential change follows AGENTS.md: password_changed_at set, lockout
// cleared, every session (and remembered device) revoked.

const EMAIL_PROMPT_GRACE_DAYS  = 7;
const EMAIL_PROMPT_SKIP_DAYS   = 90;
const EMAIL_RESET_TTL_MINUTES  = 30;
const EMAIL_RESET_PER_IP_HOUR  = 10;
const EMAIL_RESET_PER_WHO_HOUR = 3;
const EMAIL_PROMPT_TABLES      = ['auth_reset_tokens', 'auth_reset_requests', 'email_prompt_skips'];

function email_identity_ready(PDO $pdo): bool
{
    if (!campaigns_ready($pdo)) {
        return false;
    }
    foreach (EMAIL_PROMPT_TABLES as $table) {
        if (!campaigns_table_exists($pdo, $table)) {
            return false;
        }
    }
    return true;
}

/** "ada.obi@gmail.com" → "a•••@gmail.com" (enough to recognise, not to harvest). */
function email_mask(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '•••';
    }
    return mb_substr($email, 0, 1, 'UTF-8') . '•••' . substr($email, $at);
}

// ---------------------------------------------------------------------------
// The prompt
// ---------------------------------------------------------------------------

/**
 * Should this signed-in person be stopped by the email prompt?
 *   none      nothing to do (confirmed, said they have none, or not ready)
 *   banner    a link is pending (under 7 days): let them in, show a banner
 *   required  send them to /auth/add_email.php
 */
function email_prompt_state(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): string
{
    if ($userId <= 0 || !email_identity_ready($pdo) || email_setting($pdo, 'signin_prompt') === '0') {
        return 'none';
    }
    $now ??= campaigns_now();
    if (member_confirmed_email($pdo, $userId) !== null) {
        return 'none';
    }
    $stmt = $pdo->prepare('SELECT ask_again_at FROM email_prompt_skips WHERE user_id = ?');
    $stmt->execute([$userId]);
    $skip = $stmt->fetchColumn();
    if ($skip !== false && campaigns_dt((string) $skip) > $now) {
        return 'none';
    }
    $stmt = $pdo->prepare("SELECT MIN(created_at) FROM email_addresses WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $pendingSince = $stmt->fetchColumn();
    if ($pendingSince !== false && $pendingSince !== null
        && campaigns_dt((string) $pendingSince) > $now->modify('-' . EMAIL_PROMPT_GRACE_DAYS . ' days')) {
        return 'banner';
    }
    return 'required';
}

/**
 * The same answer, cached in the session for 5 minutes (header.php asks on
 * every page). email_prompt_forget() clears it after any change.
 */
function email_prompt_state_cached(PDO $pdo, int $userId): string
{
    $cached = $_SESSION['email_prompt'] ?? null;
    if (is_array($cached) && ($cached['uid'] ?? 0) === $userId && ($cached['at'] ?? 0) > time() - 300) {
        return (string) $cached['state'];
    }
    try {
        $state = email_prompt_state($pdo, $userId);
    } catch (Throwable $e) {
        error_log('[email] prompt state: ' . $e->getMessage());
        $state = 'none'; // never lock anyone out because of our own error
    }
    $_SESSION['email_prompt'] = ['uid' => $userId, 'state' => $state, 'at' => time()];
    return $state;
}

function email_prompt_forget(): void
{
    unset($_SESSION['email_prompt']);
}

/**
 * The reminder header.php shows while a link is pending: one tap resends it
 * (and comes back to this page), or change the address.
 */
function email_prompt_banner(PDO $pdo, int $userId): string
{
    if (!function_exists('ui_banner')) {
        return '';
    }
    $pending = email_address_state($pdo, $userId)['pending'] ?? null;
    if ($pending === null) {
        return '';
    }
    $here = function_exists('auth_safe_next') ? (auth_safe_next((string) ($_SERVER['REQUEST_URI'] ?? '')) ?? '/index.php') : '/index.php';
    $actions = '<form method="post" action="/auth/add_email.php" class="flex items-center gap-3">'
        . ui_csrf_field()
        . '<input type="hidden" name="do" value="resend">'
        . '<input type="hidden" name="back" value="' . ui_e($here) . '">'
        . '<input type="hidden" name="next" value="' . ui_e($here) . '">'
        . ui_button('Resend', ['variant' => 'secondary', 'size' => 'sm', 'type' => 'submit'])
        . '<a class="ui-link" href="/auth/add_email.php?next=' . ui_e(rawurlencode($here)) . '">Wrong email?</a>'
        . '</form>';
    return ui_banner('info', [
        'title'   => 'Confirm your email',
        'text'    => 'Sent to ' . email_mask($pending) . '.',
        'info'    => 'Tap the link in that email. You can keep working until then; after a week we will ask again.',
        'actions' => $actions,
    ]);
}

/** "I don't have an email" — asked again in 90 days. Recorded by the person or a worker. */
function email_prompt_skip(PDO $pdo, int $userId, ?int $byUserId = null): void
{
    $now = campaigns_now();
    $pdo->prepare('DELETE FROM email_prompt_skips WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare("INSERT INTO email_prompt_skips (user_id, reason, recorded_by, created_at, ask_again_at) VALUES (?, 'no_email', ?, ?, ?)")
        ->execute([$userId, $byUserId, campaigns_sql_dt($now), campaigns_sql_dt($now->modify('+' . EMAIL_PROMPT_SKIP_DAYS . ' days'))]);
    email_prompt_forget();
}

/** An address was given after all: the "no email" note goes. */
function email_prompt_unskip(PDO $pdo, int $userId): void
{
    if (campaigns_table_exists($pdo, 'email_prompt_skips')) {
        $pdo->prepare('DELETE FROM email_prompt_skips WHERE user_id = ?')->execute([$userId]);
    }
    email_prompt_forget();
}

// ---------------------------------------------------------------------------
// Sign-in
// ---------------------------------------------------------------------------

/**
 * Who is signing in with this identifier?
 *   1. a confirmed personal email (the person's own, proven by a link);
 *   2. otherwise users.email (the church sign-in name, or an address on file).
 * moved_to is set (masked) when the identifier is a church sign-in name and
 * the person has since confirmed a personal email: they sign in with that now.
 *
 * @return array{user:?array, via:string, moved_to:?string}
 */
function email_signin_lookup(PDO $pdo, string $identifier): array
{
    $id = email_normalize($identifier);
    $out = ['user' => null, 'via' => 'none', 'moved_to' => null];
    if ($id === '') {
        return $out;
    }
    $uid = member_by_confirmed_email($pdo, $id);
    if ($uid !== null) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return ['user' => $user, 'via' => 'personal', 'moved_to' => null];
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return $out;
    }
    $out['user'] = $user;
    $out['via'] = 'signin_name';
    $confirmed = member_confirmed_email($pdo, (int) $user['id']);
    if ($confirmed !== null && $confirmed !== email_normalize((string) $user['email'])
        && email_domain(email_normalize((string) $user['email'])) === SECURITY_LOGIN_EMAIL_DOMAIN_FOR_EMAIL) {
        $out['moved_to'] = email_mask($confirmed);
    }
    return $out;
}

// The church-issued sign-in domain (includes/security_helpers.php names it
// SECURITY_LOGIN_EMAIL_DOMAIN; repeated so this file loads on its own).
const SECURITY_LOGIN_EMAIL_DOMAIN_FOR_EMAIL = 'hodlc.com';

// ---------------------------------------------------------------------------
// Forgot password
// ---------------------------------------------------------------------------

/**
 * Someone asked for a reset link. The answer is ALWAYS the same (no way to
 * learn whether an account or an address exists); the link goes only to a
 * confirmed personal email of an active account.
 *
 * @return array{ok:bool, throttled:bool, sent:bool} sent is for logs and tests, never shown
 */
function email_reset_request(PDO $pdo, string $identifier, string $ip): array
{
    $out = ['ok' => true, 'throttled' => false, 'sent' => false];
    if (!email_identity_ready($pdo)) {
        return $out;
    }
    $now = campaigns_now();
    $hour = campaigns_sql_dt($now->modify('-1 hour'));
    $ipHash = hash('sha256', 'hod-reset-ip|' . $ip);
    $whoHash = hash('sha256', 'hod-reset-who|' . email_normalize($identifier));

    $pdo->prepare('DELETE FROM auth_reset_requests WHERE created_at < ?')->execute([campaigns_sql_dt($now->modify('-1 day'))]);
    $count = $pdo->prepare('SELECT
            (SELECT COUNT(*) FROM auth_reset_requests WHERE ip_hash = ? AND created_at >= ?) AS by_ip,
            (SELECT COUNT(*) FROM auth_reset_requests WHERE who_hash = ? AND created_at >= ?) AS by_who');
    $count->execute([$ipHash, $hour, $whoHash, $hour]);
    $c = $count->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('INSERT INTO auth_reset_requests (ip_hash, who_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$ipHash, $whoHash, campaigns_sql_dt($now)]);
    if ((int) $c['by_ip'] >= EMAIL_RESET_PER_IP_HOUR || (int) $c['by_who'] >= EMAIL_RESET_PER_WHO_HOUR) {
        $out['throttled'] = true;
        return $out;
    }

    $found = email_signin_lookup($pdo, $identifier);
    $user = $found['user'];
    if ($user === null) {
        return $out;
    }
    $status = strtolower((string) ($user['account_status'] ?? 'active'));
    if ($status !== 'active') {
        return $out;
    }
    $to = member_confirmed_email($pdo, (int) $user['id']);
    if ($to === null || email_is_suppressed($pdo, $to)) {
        return $out;
    }

    $token = campaigns_token(32);
    $nowSql = campaigns_sql_dt($now);
    // A newer link replaces any earlier one.
    $pdo->prepare('UPDATE auth_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([$nowSql, (int) $user['id']]);
    $pdo->prepare('INSERT INTO auth_reset_tokens (user_id, token_hash, sent_to, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int) $user['id'], hash('sha256', $token), $to, $nowSql,
                   campaigns_sql_dt($now->modify('+' . EMAIL_RESET_TTL_MINUTES . ' minutes'))]);

    $queued = email_send_doc($pdo, email_reset_doc(site_url('/auth/reset_password.php?t=' . rawurlencode($token))), $user + ['email' => $to], [
        'lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_reset', 'to_email' => $to, 'now' => true,
    ]);
    if (function_exists('security_log')) {
        security_log($pdo, 'password_reset_link_sent', (int) $user['id'], 'Password reset link sent to ' . email_mask($to) . '.', null, 'System');
    }
    $out['sent'] = !empty($queued['ok']);
    if (!$out['sent']) {
        error_log('[email] could not queue a reset link for user ' . $user['id'] . ': ' . ($queued['message'] ?? $queued['code'] ?? 'unknown'));
    }
    $out['token'] = $token; // for tests only: callers never show or log it
    return $out;
}

/** A reset link's state for the page's GET. @return array{state:string, user_id:?int, first_name:string} */
function email_reset_peek(PDO $pdo, string $token): array
{
    $none = ['state' => 'invalid', 'user_id' => null, 'first_name' => ''];
    if ($token === '' || !email_identity_ready($pdo)) {
        return $none;
    }
    $stmt = $pdo->prepare('SELECT t.user_id, t.expires_at, t.used_at, u.first_name
                             FROM auth_reset_tokens t JOIN users u ON u.id = t.user_id
                            WHERE t.token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $none;
    }
    $state = $row['used_at'] !== null ? 'used'
        : (campaigns_dt((string) $row['expires_at']) < campaigns_now() ? 'expired' : 'valid');
    return ['state' => $state, 'user_id' => (int) $row['user_id'], 'first_name' => (string) $row['first_name']];
}

/**
 * Set a new password from a reset link.
 *
 * @return array{ok:bool, code?:string, problems?:list<string>, user_id?:int}
 *   code: INVALID | EXPIRED | USED | MISMATCH | WEAK | NOT_ACTIVE
 */
function email_reset_complete(PDO $pdo, string $token, string $password, string $confirm): array
{
    $peek = email_reset_peek($pdo, $token);
    if ($peek['state'] !== 'valid') {
        return ['ok' => false, 'code' => strtoupper($peek['state'])];
    }
    if ($password !== $confirm) {
        return ['ok' => false, 'code' => 'MISMATCH'];
    }
    $uid = (int) $peek['user_id'];
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (strtolower((string) ($user['account_status'] ?? 'active')) !== 'active') {
        return ['ok' => false, 'code' => 'NOT_ACTIVE'];
    }
    $problems = function_exists('security_password_problems') ? security_password_problems($password, $user) : [];
    if ($problems !== []) {
        return ['ok' => false, 'code' => 'WEAK', 'problems' => $problems];
    }

    $now = campaigns_sql_dt(campaigns_now());
    // Use the link first: two tabs pressing at once cannot both win.
    $use = $pdo->prepare('UPDATE auth_reset_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL');
    $use->execute([$now, hash('sha256', $token)]);
    if ($use->rowCount() !== 1) {
        return ['ok' => false, 'code' => 'USED'];
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if (function_exists('security_schema_ready') && security_schema_ready($pdo)) {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0,
                              failed_login_count = 0, locked_until = NULL WHERE id = ?')
            ->execute([$hash, $uid]);
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
    }
    $pdo->prepare('UPDATE auth_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([$now, $uid]);
    if (function_exists('security_revoke_sessions')) {
        security_revoke_sessions($pdo, $uid, $uid, 'Password reset by email link');
    }
    if (function_exists('security_log')) {
        security_log($pdo, 'password_reset_by_email', $uid, 'Password changed with an emailed reset link; every session signed out.', null, 'System');
    }
    if (function_exists('security_record_attempt')) {
        security_record_attempt($pdo, $uid, (string) ($user['email'] ?? ''), true, null, 'password_reset');
    }
    $to = member_confirmed_email($pdo, $uid);
    if ($to !== null) {
        email_send_doc($pdo, email_password_changed_doc(), $user + ['email' => $to], [
            'lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_changed', 'to_email' => $to, 'now' => true,
        ]);
    }
    return ['ok' => true, 'user_id' => $uid];
}

function email_reset_doc(string $link): array
{
    return [
        'v' => 1,
        'subject' => 'Reset your password',
        'preheader' => 'This link works for ' . EMAIL_RESET_TTL_MINUTES . ' minutes.',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'Reset your password'],
            email_doc_paragraphs(
                'Hi {{first_name}},',
                'Someone asked to reset the password of your Household of David Lekki account. If it was you, choose a new password with the button below.'
            ),
            ['type' => 'button', 'label' => 'Choose a new password', 'href' => $link, 'style' => 'primary', 'align' => 'left'],
            email_doc_paragraphs(
                'The link works once, for ' . EMAIL_RESET_TTL_MINUTES . ' minutes. If you did not ask for this, ignore this email: your password stays as it is.'
            ),
            ['type' => 'footer'],
        ],
    ];
}

function email_password_changed_doc(): array
{
    return [
        'v' => 1,
        'subject' => 'Your password was changed',
        'preheader' => 'Every device has been signed out.',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'Your password was changed'],
            email_doc_paragraphs(
                'Hi {{first_name}},',
                'The password of your Household of David Lekki account was just changed with a reset link, and every device was signed out.',
                'If this was not you, contact the church office straight away so they can lock the account.'
            ),
            ['type' => 'footer'],
        ],
    ];
}
