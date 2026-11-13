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
    $here = function_exists('auth_safe_next') ? (auth_safe_next((string) ($_SERVER['REQUEST_URI'] ?? '')) ?? '/') : '/';
    $actions = '<form method="post" action="/auth/add_email" class="flex items-center gap-3">'
        . ui_csrf_field()
        . '<input type="hidden" name="do" value="resend">'
        . '<input type="hidden" name="back" value="' . ui_e($here) . '">'
        . '<input type="hidden" name="next" value="' . ui_e($here) . '">'
        . ui_button('Resend', ['variant' => 'secondary', 'size' => 'sm', 'type' => 'submit'])
        . '<a class="ui-link" href="/auth/add_email?next=' . ui_e(rawurlencode($here)) . '">Wrong email?</a>'
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
        // A church sign-in name that a confirmed personal email has since
        // replaced in users.email (email_sync_users_email()): the person, and
        // where they sign in now.
        $owner = signin_retired_name_owner($pdo, $id);
        if ($owner !== null) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$owner]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $now = member_confirmed_email($pdo, (int) $user['id']) ?? email_normalize((string) $user['email']);
                return ['user' => $user, 'via' => 'retired', 'moved_to' => $now !== '' ? email_mask($now) : null];
            }
        }
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
// Retired sign-in names (owner's decision, 13 Nov 2026: the @hodlc.com names
// die out). Once a personal email is confirmed it takes the @hodlc.com name's
// place in users.email; the old name is kept here only so that typing it
// still explains where the person signs in now.
// ---------------------------------------------------------------------------

/** Whose retired sign-in name is this? Null when nobody's (or before the migration). */
function signin_retired_name_owner(PDO $pdo, string $name): ?int
{
    $name = email_normalize($name);
    if ($name === '' || email_domain($name) !== SECURITY_LOGIN_EMAIL_DOMAIN_FOR_EMAIL) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM auth_retired_signin_names WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    } catch (PDOException $e) {
        return null; // the migration has not run yet
    }
}

/**
 * The swap for everyone who confirmed a personal email while users.email
 * still held their @hodlc.com name (db/backfill_signin_names.php, on every
 * deploy; finds nothing once done). Same function as a live confirmation.
 *
 * @return array{ready:bool, checked:int, moved:int, kept:int}
 */
function signin_names_backfill(PDO $pdo, bool $apply = true): array
{
    $out = ['ready' => false, 'checked' => 0, 'moved' => 0, 'kept' => 0];
    try {
        $pdo->query('SELECT 1 FROM auth_retired_signin_names LIMIT 1');
        $rows = $pdo->query("SELECT u.id, u.email, a.confirmed_email
                               FROM users u JOIN email_addresses a ON a.confirmed_user_id = u.id
                              WHERE u.email LIKE '%@" . SECURITY_LOGIN_EMAIL_DOMAIN_FOR_EMAIL . "'
                              ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $out; // the migrations have not run yet
    }
    $out['ready'] = true;
    $now = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    foreach ($rows as $row) {
        $out['checked']++;
        if (!$apply) {
            continue;
        }
        email_sync_users_email($pdo, (int) $row['id'], email_normalize((string) $row['confirmed_email']));
        $now->execute([(int) $row['id']]);
        if (email_normalize((string) $now->fetchColumn()) === email_normalize((string) $row['confirmed_email'])) {
            $out['moved']++;
        } else {
            $out['kept']++; // the address is another account's users.email
        }
    }
    return $out;
}

/** Keep a sign-in name that is about to leave users.email. False when it could not be kept. */
function signin_retire_name(PDO $pdo, int $userId, string $name): bool
{
    $name = email_normalize($name);
    try {
        $pdo->prepare('INSERT INTO auth_retired_signin_names (name, user_id, retired_at) VALUES (?, ?, ?)')
            ->execute([$name, $userId, campaigns_sql_dt(campaigns_now())]);
        return true;
    } catch (PDOException $e) {
        // Already kept (for this person) is fine; a missing table is not.
        return campaigns_is_duplicate_key($e) && signin_retired_name_owner($pdo, $name) === $userId;
    }
}

// ---------------------------------------------------------------------------
// Sign-in by phone (owner's decision, 13 Nov 2026). The number on someone's
// record signs them in with their password. Several people can share one
// number (spouses, a parent and child): the password decides whose account
// opens (api/auth_api.php).
// ---------------------------------------------------------------------------

const SIGNIN_PHONE_MAX_ACCOUNTS = 5;

/** Does what was typed look like a phone number rather than an email? */
function signin_is_phone(string $typed): bool
{
    $typed = trim($typed);
    return $typed !== '' && !str_contains($typed, '@')
        && preg_match('/^\+?[0-9\s().\-]{7,24}$/', $typed) === 1
        && strlen((string) preg_replace('/\D/', '', $typed)) >= 10;
}

/**
 * One key per number, however it was written: a Nigerian number becomes 234
 * and its ten digits (0803…, +234 803…, 234 0803…, 803…), any other its
 * digits without a leading 00. Null when it cannot be a phone number.
 */
function signin_phone_key(string $raw): ?string
{
    $d = (string) preg_replace('/\D/', '', $raw);
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    $len = strlen($d);
    if ($len === 11 && $d[0] === '0') {
        return '234' . substr($d, 1);
    }
    if ($len === 14 && str_starts_with($d, '2340')) {
        return '234' . substr($d, 4);
    }
    if ($len === 10 && in_array($d[0], ['7', '8', '9'], true)) {
        return '234' . $d;
    }
    return $len >= 10 && $len <= 15 ? $d : null;
}

/**
 * The accounts whose record has this number (a record may hold two, "0803… /
 * 0805…"), oldest first, at most SIGNIN_PHONE_MAX_ACCOUNTS.
 *
 * @return list<array> users rows
 */
function signin_phone_users(PDO $pdo, string $phone): array
{
    $key = signin_phone_key($phone);
    if ($key === null) {
        return [];
    }
    // The last nine digits narrow the scan; the key decides.
    $stmt = $pdo->prepare("SELECT * FROM users
                            WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') LIKE ?
                            ORDER BY id LIMIT 50");
    $stmt->execute(['%' . substr($key, -9) . '%']);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (preg_split('#[,;/|]|\s{2,}#', (string) $row['phone']) ?: [] as $part) {
            if (signin_phone_key($part) === $key) {
                $out[] = $row;
                break;
            }
        }
        if (count($out) >= SIGNIN_PHONE_MAX_ACCOUNTS) {
            break;
        }
    }
    return $out;
}

/**
 * Who might be signing in with what they typed: the account an email names
 * (email_signin_lookup()), or every account on a phone number.
 *
 * @return array{users:list<array>, via:string, moved_to:?string}
 */
function signin_candidates(PDO $pdo, string $typed): array
{
    if (signin_is_phone($typed)) {
        $users = signin_phone_users($pdo, $typed);
        return ['users' => $users, 'via' => $users ? 'phone' : 'none', 'moved_to' => null];
    }
    $found = email_signin_lookup($pdo, $typed);
    return ['users' => $found['user'] ? [$found['user']] : [], 'via' => $found['via'], 'moved_to' => $found['moved_to']];
}

/** Statuses that have not joined yet: Connect and Embrace's ladder. They join the app once promoted. */
const SIGNIN_NOT_YET_MEMBER = ['1st_Timer', '2nd_Timer', '3rd_Timer', 'Visitor'];

/**
 * Whose FIRST password is being set on auth/setup_password.php? By email as
 * before, or by phone: only someone who has joined (not a first timer or
 * visitor), and only an account that has never had a password. Two such
 * accounts on one number need the first name to tell them apart.
 *
 * code: ok | none | has_password | name_needed. user: the account (ok), or
 * the one that already has a password (has_password, for the audit log).
 *
 * @return array{code:string, user:?array}
 */
function signin_setup_account(PDO $pdo, string $typed, string $firstName = ''): array
{
    if (!signin_is_phone($typed)) {
        $user = email_signin_lookup($pdo, $typed)['user'];
        if ($user === null) {
            return ['code' => 'none', 'user' => null];
        }
        return ['code' => empty($user['password_hash']) ? 'ok' : 'has_password', 'user' => $user];
    }
    $people = array_values(array_filter(signin_phone_users($pdo, $typed), static fn (array $u): bool =>
        !in_array((string) ($u['spiritual_status'] ?? ''), SIGNIN_NOT_YET_MEMBER, true)));
    $first = mb_strtolower(trim($firstName));
    if ($first !== '') {
        $people = array_values(array_filter($people, static fn (array $u): bool =>
            in_array($first, [mb_strtolower(trim((string) $u['first_name'])), mb_strtolower(trim((string) $u['last_name']))], true)));
    }
    $fresh = array_values(array_filter($people, static fn (array $u): bool => empty($u['password_hash'])));
    if (count($fresh) === 1) {
        return ['code' => 'ok', 'user' => $fresh[0]];
    }
    if (count($fresh) > 1) {
        return ['code' => 'name_needed', 'user' => null];
    }
    return $people ? ['code' => 'has_password', 'user' => $people[0]] : ['code' => 'none', 'user' => null];
}

/** The one answer for anything that did not match (never says which part). */
const SIGNIN_WRONG = 'Wrong email, phone or password.';

/**
 * The password decides who is signing in among the candidates: one account
 * for an email, up to five on a shared phone number. Locked accounts are not
 * tried. A wrong password counts against every account it was tried on and
 * locks each one that reaches SECURITY_MAX_FAILED_LOGINS. Two accounts that
 * share the number AND the password are refused (the email tells them apart).
 * $security: the account-security columns exist (security_schema_ready()).
 *
 * @param list<array> $candidates users rows, from signin_candidates()
 * @return array{ok:bool, user?:array, message?:string}
 */
function signin_check_password(PDO $pdo, array $candidates, string $password, string $typed, bool $security): array
{
    $max = defined('SECURITY_MAX_FAILED_LOGINS') ? SECURITY_MAX_FAILED_LOGINS : 5;
    $lockMinutes = defined('SECURITY_LOCK_MINUTES') ? SECURITY_LOCK_MINUTES : 15;
    $record = static function (?int $userId, ?string $why) use ($pdo, $typed): void {
        if (function_exists('security_record_attempt')) {
            security_record_attempt($pdo, $userId, $typed, false, $why);
        }
    };
    $now = campaigns_now();
    $open = [];
    $lockedUntil = null;
    $lockedId = null;
    foreach ($candidates as $c) {
        $until = $security && !empty($c['locked_until']) ? strtotime((string) $c['locked_until']) : false;
        if ($until !== false && $until > $now->getTimestamp()) {
            if ($lockedUntil === null || $until < $lockedUntil) {
                [$lockedUntil, $lockedId] = [$until, (int) $c['id']];
            }
        } else {
            $open[] = $c;
        }
    }
    if ($candidates !== [] && $open === []) {
        $minutes = max(1, (int) ceil(($lockedUntil - $now->getTimestamp()) / 60));
        $record($lockedId, 'account_locked');
        return ['ok' => false, 'message' => "Too many failed sign-in attempts. This account is locked for another {$minutes} minute"
            . ($minutes === 1 ? '' : 's') . '. Contact an administrator if you need it opened sooner.'];
    }

    $matches = array_values(array_filter($open, static fn (array $c): bool =>
        !empty($c['password_hash']) && password_verify($password, (string) $c['password_hash'])));
    if (count($matches) === 1) {
        return ['ok' => true, 'user' => $matches[0]];
    }
    if (count($matches) > 1) {
        $record(null, 'shared_phone_same_password');
        return ['ok' => false, 'message' => 'Two accounts share this number and password. Sign in with your email.'];
    }
    if ($open === [] || !$security) {
        // Nobody by that name (or before the security migration): no detail.
        $record(null, 'unknown_email');
        return ['ok' => false, 'message' => SIGNIN_WRONG];
    }

    $left = PHP_INT_MAX;
    $lockedNow = 0;
    foreach ($open as $c) {
        $id = (int) $c['id'];
        $failures = (int) ($c['failed_login_count'] ?? 0) + 1;
        if ($failures >= $max) {
            $pdo->prepare('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?')
                ->execute([$failures, campaigns_sql_dt($now->modify('+' . $lockMinutes . ' minutes')), $id]);
            $record($id, 'bad_password_locked');
            if (function_exists('security_log')) {
                security_log($pdo, 'account_auto_locked', $id,
                    "Account locked automatically after {$failures} consecutive failed sign-in attempts.", null, 'System');
            }
            $lockedNow++;
        } else {
            $pdo->prepare('UPDATE users SET failed_login_count = ? WHERE id = ?')->execute([$failures, $id]);
            $record($id, 'bad_password');
            $left = min($left, $max - $failures);
        }
    }
    if ($lockedNow === count($open)) {
        return ['ok' => false, 'message' => 'Too many failed sign-in attempts. This account has been locked for '
            . $lockMinutes . ' minutes for your protection.'];
    }
    return ['ok' => false, 'message' => SIGNIN_WRONG
        . ($left <= 2 ? " {$left} attempt" . ($left === 1 ? '' : 's') . ' left before this account is locked.' : '')];
}

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

    // An email names one account; a shared phone number can name several, and
    // each of them gets its own link at its own confirmed address.
    foreach (signin_candidates($pdo, $identifier)['users'] as $user) {
        $sent = email_reset_send($pdo, $user);
        if ($sent !== null) {
            $out['sent'] = $sent['sent'] || $out['sent'];
            $out['token'] = $sent['token']; // for tests only: callers never show or log it
        }
    }
    return $out;
}

/**
 * One reset link for one account, to its confirmed personal email. Null when
 * the account cannot have one (not active, no confirmed address, suppressed).
 *
 * @return array{sent:bool, token:string}|null
 */
function email_reset_send(PDO $pdo, array $user): ?array
{
    $status = strtolower((string) ($user['account_status'] ?? 'active'));
    if ($status !== 'active') {
        return null;
    }
    $to = member_confirmed_email($pdo, (int) $user['id']);
    if ($to === null || email_is_suppressed($pdo, $to)) {
        return null;
    }

    $now = campaigns_now();
    $token = campaigns_token(32);
    $nowSql = campaigns_sql_dt($now);
    // A newer link replaces any earlier one.
    $pdo->prepare('UPDATE auth_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([$nowSql, (int) $user['id']]);
    $pdo->prepare('INSERT INTO auth_reset_tokens (user_id, token_hash, sent_to, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int) $user['id'], hash('sha256', $token), $to, $nowSql,
                   campaigns_sql_dt($now->modify('+' . EMAIL_RESET_TTL_MINUTES . ' minutes'))]);

    $queued = email_send_doc($pdo, email_reset_doc(site_url('/auth/reset_password?t=' . rawurlencode($token))), $user + ['email' => $to], [
        'lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_reset', 'to_email' => $to, 'now' => true,
    ]);
    if (function_exists('security_log')) {
        security_log($pdo, 'password_reset_link_sent', (int) $user['id'], 'Password reset link sent to ' . email_mask($to) . '.', null, 'System');
    }
    $sent = !empty($queued['ok']);
    if (!$sent) {
        error_log('[email] could not queue a reset link for user ' . $user['id'] . ': ' . ($queued['message'] ?? $queued['code'] ?? 'unknown'));
    }
    return ['sent' => $sent, 'token' => $token];
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
