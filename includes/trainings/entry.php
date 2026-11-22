<?php
// /includes/trainings/entry.php
//
// The way into a standalone test from its QR code, as a short conversation (owner, 21-22 Nov 2026: "very very short,
// punchy and lean… everything should not take anyone more than 2 minutes… if IDI has to intervene for anyone then we
// have failed, unless they don't have their numbers on the system at all").
//
//   phone ─┬─ not on HOD ............................. Information and Data Insights (IDI), the one dead end
//          ├─ a shared number ........................ "Which one is you?"
//          ├─ has a password ......................... password ─ in (their email checked once, if not yet)
//          │                                            └─ "I can't remember": a code to their email → new password → in
//          └─ never had one .......................... a code to their email → choose a password → in
//   No email on record (or "I don't use this email"): they type one; when it is to reset a password, their birthday
//   (day and month) first; then the code goes there. The right code confirms that email and makes it theirs, once:
//   next time there is nothing to type but the password.
//
// A "real" email is a confirmed one, one waiting to be confirmed, or users.email when it is not a church sign-in name
// (@hodlc.com, email_looks_personal()). Codes: includes/auth_codes.php. The birthday check captures birthdays more
// than it guards (owner: a different answer may replace the one on record). Staff take the same way in as everyone
// (owner, 22 Nov: no dead ends); when a staff password is set or reset here, IDI and the person are alerted
// (trainings_entry_staff_alert()). An email already on another account: "please type another one" (owner). The only
// dead ends: a number HOD does not know, an account on hold. Signed in for 30 days (trainings_entry_sign_in()).
// Limits are per person, and generous per IP: a full church hall shares one Wi-Fi.
//
// Page: test.php (public, strict CSP), script assets/ui/js/pages/test-entry.js, endpoint api/test_entry_api.php (CSRF
// checked). The server decides every step; the page only says it. State: $_SESSION['test_entry'], TRAININGS_ENTRY_TTL.
// Guide: docs/EXAMS.md §9. Tests: tests/trainings/run.php, "QR entry to a test".

require_once dirname(__DIR__) . '/auth_codes.php';

const TRAININGS_ENTRY_TTL = 1200;            // 20 minutes from the number to the last step
const TRAININGS_ENTRY_LOOKUPS_PER_IP = 150;  // numbers HOD does not know, per IP per 15 minutes (a hall shares one Wi-Fi)
const TRAININGS_ENTRY_VERIFIED_TTL = 900;    // a right code allows a new password for 15 minutes
const TRAININGS_ENTRY_IDI = 'Please contact Information and Data Insights (IDI).';
const TRAININGS_ENTRY_TAKEN = 'That email is on another account. Please type another one.';
const TRAININGS_ENTRY_MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september',
    'october', 'november', 'december'];

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

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

function trainings_entry_first(array $u): string
{
    return trim((string) ($u['first_name'] ?? '')) ?: 'friend';
}

/** Is the account open (not suspended, revoked or disabled)? */
function trainings_entry_open_account(array $u): bool
{
    return !in_array(strtolower((string) ($u['account_status'] ?? 'active')), ['suspended', 'revoked', 'disabled'], true);
}

/** Does the account hold a staff role (user_roles)? A password set or reset here then alerts IDI (trainings_entry_staff_alert()). */
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

/** Is this address another account's (confirmed there)? One email, one account in HOD. */
function trainings_entry_email_taken(PDO $pdo, string $email, int $userId): bool
{
    if (!email_identity_ready($pdo)) {
        return false;
    }
    $owner = member_by_confirmed_email($pdo, email_normalize($email));
    return $owner !== null && $owner !== $userId;
}

/**
 * Who hears about an account-security event: everyone who can open the Security Centre ("who can act is who is
 * told", perm_holders(); IDI's people are given it in Roles & permissions), else the Super Admins (break-glass).
 */
function trainings_entry_idi_people(PDO $pdo): array
{
    if (!function_exists('perm_holders')) {
        return [];
    }
    try {
        $ids = perm_holders($pdo, 'security', 'view');
        return $ids !== [] ? $ids : perm_holders($pdo, 'security', 'view', null, true);
    } catch (Throwable $e) {
        error_log('[test entry] security alert people: ' . $e->getMessage());
        return [];
    }
}

/**
 * A staff password was set or reset from a test QR code (owner, 22 Nov: staff take the same way in, "+ alert"):
 * an app alert (and push) to IDI, and to the person, so a takeover cannot go unnoticed. Never breaks the way in.
 */
function trainings_entry_staff_alert(PDO $pdo, array $user, bool $reset): void
{
    try {
        $name = trainings_entry_name($user);
        $what = $reset ? 'reset' : 'set for the first time';
        $notify = static function (array $ids, string $title, string $message, string $link) use ($pdo): void {
            $stmt = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
            foreach ($ids as $id) {
                $stmt->execute([(int) $id, $title, $message, $link]);
            }
        };
        $others = static fn (array $ids): array => array_values(array_filter($ids, static fn ($id) => (int) $id !== (int) $user['id']));
        $idi = $others(trainings_entry_idi_people($pdo));
        if ($idi === [] && function_exists('perm_holders')) {
            $idi = $others(perm_holders($pdo, 'security', 'view', null, true));   // they were the only one: the Super Admins too
        }
        $notify($idi, 'Staff password ' . ($reset ? 'reset' : 'set'), $name . '\'s password was ' . $what . ' from a test QR code. Check it was them.',
            '/modules/security/');
        $notify([(int) $user['id']], 'Your password was ' . ($reset ? 'reset' : 'set'), 'From a test QR code just now. Not you? Contact IDI straight away.',
            '/modules/profile/');
        if (function_exists('security_log')) {
            security_log($pdo, 'staff_password_test_qr', (int) $user['id'], 'Staff password ' . $what . ' from a test QR code; IDI alerted.', (int) $user['id'], $name);
        }
    } catch (Throwable $e) {
        error_log('[test entry] staff alert: ' . $e->getMessage());
    }
}

/** "tom…h@gmail.com": the first three characters and the last one before the @ (owner's choice). */
function trainings_entry_mask(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '…';
    }
    $local = substr($email, 0, $at);
    $len = mb_strlen($local, 'UTF-8');
    $shown = $len <= 4 ? mb_substr($local, 0, 1, 'UTF-8') . '…' : mb_substr($local, 0, 3, 'UTF-8') . '…' . mb_substr($local, -1, 1, 'UTF-8');
    return $shown . substr($email, $at);
}

/**
 * The button that opens their mailbox ("Open Gmail"), for the providers people here use; null for others.
 * href is the web inbox; android (the app's package) and ios (the app's own address) let the page open the APP
 * itself on a phone (owner, 22 Nov: "Is there any way to open the app directly?"): test-entry.js mailHtml().
 */
function trainings_entry_mail_link(string $email): ?array
{
    $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    return match (true) {
        in_array($domain, ['gmail.com', 'googlemail.com'], true) => ['label' => 'Open Gmail', 'href' => 'https://mail.google.com/mail/',
            'android' => 'com.google.android.gm', 'ios' => 'googlegmail://'],
        str_starts_with($domain, 'yahoo.') || $domain === 'ymail.com' => ['label' => 'Open Yahoo Mail', 'href' => 'https://mail.yahoo.com/',
            'android' => 'com.yahoo.mobile.client.android.mail', 'ios' => 'ymail://'],
        in_array($domain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], true) => ['label' => 'Open Outlook', 'href' => 'https://outlook.live.com/mail/',
            'android' => 'com.microsoft.office.outlook', 'ios' => 'ms-outlook://'],
        in_array($domain, ['icloud.com', 'me.com', 'mac.com'], true) => ['label' => 'Open Mail', 'href' => 'https://www.icloud.com/mail',
            'android' => null, 'ios' => 'message://'],
        default => null,
    };
}

/** The email a code can go to: confirmed, else waiting to be confirmed, else a real one on file. Null when none. */
function trainings_entry_real_email(PDO $pdo, int $userId): ?string
{
    if (!email_identity_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $e = email_normalize((string) ($stmt->fetchColumn() ?: ''));
        return email_looks_personal($e) ? $e : null;
    }
    $st = email_address_state($pdo, $userId);
    return $st['confirmed'] ?? $st['pending'] ?? $st['on_file'];
}

// ---------------------------------------------------------------------------
// Birthdays: day and month (owner: the year on record is often a stand-in, 2000)
// ---------------------------------------------------------------------------

/**
 * A birthday however it is typed: "12 March", "march 12th", "12/03" (day first, as here), "12-3-1990", "Mrach 12",
 * "the 12th of sept". The year is ignored. Null when it cannot be read.
 * @return array{month:int, day:int}|null
 */
function trainings_entry_parse_birthday(string $raw): ?array
{
    $s = mb_strtolower(trim($raw), 'UTF-8');
    $s = (string) preg_replace('/(\d+)\s*(st|nd|rd|th)\b/u', '$1', $s);
    $s = (string) preg_replace('/[^a-z0-9]+/u', ' ', $s);
    $words = $nums = [];
    foreach (preg_split('/\s+/', trim($s)) ?: [] as $tok) {
        if ($tok === '' || in_array($tok, ['of', 'the', 'on', 'my', 'birthday', 'is', 'born', 'day', 'in'], true)) {
            continue;
        }
        if (ctype_digit($tok)) {
            if (strlen($tok) <= 2) {
                $nums[] = (int) $tok;
            }
            continue;   // a year (four digits) or noise
        }
        $words[] = $tok;
    }
    $month = null;
    foreach ($words as $w) {
        $month = trainings_entry_month_word($w);
        if ($month !== null) {
            break;
        }
    }
    if ($month !== null) {
        $day = $nums[0] ?? null;
    } elseif (count($nums) >= 2) {
        [$day, $month] = [$nums[0], $nums[1]];
        if ($month > 12 && $day <= 12) {
            [$day, $month] = [$month, $day];   // written month first
        }
    } else {
        return null;
    }
    if ($day === null || $month < 1 || $month > 12 || $day < 1 || $day > trainings_entry_days_in_month($month)) {
        return null;
    }
    return ['month' => $month, 'day' => $day];
}

/** Days in a month, February allowing the 29th. */
function trainings_entry_days_in_month(int $month): int
{
    return [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$month] ?? 0;
}

/** A month from a word, forgiving: "march", "mar", "sept", "Mrach", "febuary". Null when nothing is close. */
function trainings_entry_month_word(string $w): ?int
{
    if (strlen($w) < 3) {
        return null;
    }
    foreach (TRAININGS_ENTRY_MONTHS as $i => $name) {
        if ($w === $name || (strlen($w) >= 3 && str_starts_with($name, $w))) {
            return $i + 1;
        }
    }
    $best = null;
    $bestScore = 99;
    foreach (TRAININGS_ENTRY_MONTHS as $i => $name) {
        $score = levenshtein($w, $name);
        if ($score < $bestScore) {
            [$best, $bestScore] = [$i + 1, $score];
        }
    }
    return $bestScore <= (strlen($w) >= 5 ? 2 : 1) ? $best : null;   // "Mrach", "febuary", "agust"
}

/** "12 March". */
function trainings_entry_birthday_text(array $b): string
{
    return $b['day'] . ' ' . ucfirst(TRAININGS_ENTRY_MONTHS[$b['month'] - 1]);
}

/** The birthday on record (day and month), or null when there is none. */
function trainings_entry_birthday_on_record(array $u): ?array
{
    $dob = (string) ($u['dob'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dob, $m) || $m[2] === '00' || $m[3] === '00') {
        return null;
    }
    return ['month' => (int) $m[2], 'day' => (int) $m[3]];
}

/** Save a birthday: the day and month given, the year kept when there is one, else 2000 (owner). */
function trainings_entry_save_birthday(PDO $pdo, array $u, array $b): void
{
    $year = preg_match('/^(\d{4})-\d{2}-\d{2}/', (string) ($u['dob'] ?? ''), $m) && $m[1] !== '0000' ? $m[1] : '2000';
    $pdo->prepare('UPDATE users SET dob = ? WHERE id = ?')->execute([sprintf('%s-%02d-%02d', $year, $b['month'], $b['day']), (int) $u['id']]);
}

// ---------------------------------------------------------------------------
// The state between steps
// ---------------------------------------------------------------------------

/** The steps' memory, or null when there is none or it is too old. */
function trainings_entry_state(): ?array
{
    $s = $_SESSION['test_entry'] ?? null;
    if (!is_array($s) || (int) ($s['at'] ?? 0) < time() - TRAININGS_ENTRY_TTL) {
        return null;
    }
    return $s;
}

function trainings_entry_put(array $values): void
{
    $_SESSION['test_entry'] = array_merge((array) ($_SESSION['test_entry'] ?? []), $values);
}

/**
 * Sign them in as a password sign-in does (api/auth_api.php steps 10-14): the session, the Security Centre's session
 * row and sign-in record, and "keep me signed in" (30 days, AUTH_REMEMBER_DAYS).
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
        return ['status' => 'error', 'message' => 'Please type a password.', 'field' => 'password'];
    }
    if ($password !== $confirm) {
        return ['status' => 'error', 'message' => 'Those two are not the same. Let\'s try again.', 'field' => 'confirm'];
    }
    $problems = function_exists('security_password_problems') ? security_password_problems($password, $user) : [];
    return $problems !== [] ? ['status' => 'error', 'message' => (string) $problems[0], 'field' => 'password', 'problems' => $problems] : null;
}

/**
 * Make $email this person's confirmed address now (a right code proved they read it): a waiting row for it if there
 * is none, then the same confirmation as its emailed link (email_capture_confirm()), which also puts it in
 * users.email and replaces an older one. False when the address belongs to someone else.
 */
function trainings_entry_activate_email(PDO $pdo, int $userId, string $email): bool
{
    if (!email_identity_ready($pdo)) {
        return true;
    }
    $email = email_normalize($email);
    if (email_address_state($pdo, $userId)['confirmed'] === $email) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT id FROM email_addresses WHERE user_id = ? AND email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, $email]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $r = email_capture_start($pdo, $userId, $email, 'signin_prompt', $userId, ['send' => false]);
        if (empty($r['ok'])) {
            return false;
        }
        if (!empty($r['already_confirmed'])) {
            return true;
        }
        $id = (int) $r['address_id'];
    }
    $token = campaigns_token(32);
    $pdo->prepare('UPDATE email_addresses SET token_hash = ?, token_expires_at = ? WHERE id = ?')
        ->execute([hash('sha256', $token), campaigns_sql_dt(campaigns_now()->modify('+1 hour')), (int) $id]);
    $r = email_capture_confirm($pdo, $token);
    email_prompt_forget();
    return !empty($r['ok']);
}

/**
 * "I don't use this email": the new address replaces the old one in users.email too (the confirmed address already
 * replaced the old confirmed one). Kept as it is when another account has that address.
 */
function trainings_entry_replace_on_file(PDO $pdo, int $userId, string $email): void
{
    $email = email_normalize($email);
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? AND id <> ?');
        $stmt->execute([$email, $userId]);
        if (!$stmt->fetchColumn()) {
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
        }
    } catch (PDOException $e) {
        error_log('[test entry] users.email not replaced for user ' . $userId . ': ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// The conversation
// ---------------------------------------------------------------------------

/** A reply: the next step and what the page needs to say it. */
function trainings_entry_reply(string $step, array $data = []): array
{
    return ['status' => 'success', 'step' => $step] + $data;
}

function trainings_entry_idi(string $why): array
{
    return trainings_entry_reply('idi', ['message' => $why . ' ' . TRAININGS_ENTRY_IDI]);
}

/** Send the code for the current way in, to $email; the code step, or an error. */
function trainings_entry_send(PDO $pdo, array $user, string $email, string $ip, bool $typed = false): array
{
    $s = trainings_entry_state() ?? [];
    $purpose = ($s['mode'] ?? '') === 'activate' ? 'confirm' : 'reset';
    $r = auth_code_send($pdo, $user, $purpose, email_normalize($email), $ip);
    if (empty($r['ok']) && ($r['code'] ?? '') !== 'TOO_SOON') {
        return ['status' => 'error', 'message' => (string) $r['message'], 'code' => (string) $r['code']];
    }
    trainings_entry_put(['target' => email_normalize($email), 'typed' => $typed, 'purpose' => $purpose]);
    return trainings_entry_reply('code', ['to' => trainings_entry_mask(email_normalize($email)), 'mail' => trainings_entry_mail_link($email),
        'typed' => $typed, 'note' => ($r['code'] ?? '') === 'TOO_SOON' ? 'already' : null]);
}

/** Where someone goes once we know who they are. */
function trainings_entry_route(PDO $pdo, array $u, string $ip): array
{
    $first = trainings_entry_first($u);
    if (!trainings_entry_open_account($u)) {
        return trainings_entry_idi('This account is on hold.');
    }
    if (!empty($u['password_hash'])) {
        trainings_entry_put(['mode' => 'password']);
        return trainings_entry_reply('password', ['name' => $first]);
    }
    // Never had a password: a code to their email first, then they choose one.
    trainings_entry_put(['mode' => 'first']);
    $real = trainings_entry_real_email($pdo, (int) $u['id']);
    if ($real !== null) {
        return ['name' => $first, 'first' => true] + trainings_entry_send($pdo, $u, $real, $ip);
    }
    return trainings_entry_reply('email', ['name' => $first, 'first' => true]);
}

/** After signing in: confirm their email once (a code), or ask for one; else straight to the test. */
function trainings_entry_after_sign_in(PDO $pdo, array $u, int $itemId, string $ip): array
{
    $uid = (int) $u['id'];
    if (!email_identity_ready($pdo) || email_address_state($pdo, $uid)['confirmed'] !== null) {
        return trainings_entry_reply('go', ['go' => exam_url_take($itemId), 'name' => trainings_entry_first($u)]);
    }
    trainings_entry_put(['mode' => 'activate']);
    $real = trainings_entry_real_email($pdo, $uid);
    if ($real !== null) {
        return trainings_entry_send($pdo, $u, $real, $ip);
    }
    if (email_prompt_state($pdo, $uid) === 'none') {
        return trainings_entry_reply('go', ['go' => exam_url_take($itemId), 'name' => trainings_entry_first($u)]);
    }
    return trainings_entry_reply('email', ['name' => trainings_entry_first($u), 'activate' => true]);
}

/**
 * One step. $in: the request; $ip: the client's address; $signIn: how to sign someone in (tests pass their own).
 * Steps: choose | password | email | typo | birthday | birthday_mismatch | code | new_password | idi | go.
 */
function trainings_entry_action(PDO $pdo, string $do, array $in, string $ip, ?callable $signIn = null): array
{
    $signIn ??= static function (array $user, string $how) use ($pdo): void {
        trainings_entry_sign_in($pdo, $user, $how);
    };
    $fail = static fn (string $message, ?string $code = null, ?string $field = null): array =>
        ['status' => 'error', 'message' => $message] + ($code ? ['code' => $code] : []) + ($field ? ['field' => $field] : []);
    $expired = $fail('That took a while, so let\'s start again with your phone number.', 'EXPIRED');

    // ---- After the test (signed in): confirm the email with a code. ----
    if ($do === 'confirm_send' || $do === 'confirm_check') {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $user = $uid > 0 ? trainings_entry_user($pdo, $uid) : null;
        if ($user === null) {
            return $fail('Please sign in again.', 'SIGNED_OUT');
        }
        return $do === 'confirm_send' ? trainings_entry_confirm_send($pdo, $user, $in, $ip) : trainings_entry_confirm_check($pdo, $user, (string) ($in['code'] ?? ''));
    }

    if ($do === 'lookup') {
        $itemId = (int) ($in['t'] ?? 0);
        if (trainings_entry_test($pdo, $itemId) === null) {
            return $fail('This test is not open right now.', 'CLOSED');
        }
        $phone = trim((string) ($in['phone'] ?? ''));
        if (!signin_is_phone($phone)) {
            return $fail('That doesn\'t look like a phone number. Try it like 0803 123 4567.', 'INVALID', 'phone');
        }
        if (trainings_entry_throttled($pdo, $ip)) {
            return $fail('Too many tries from here. Please wait a few minutes.', 'THROTTLED');
        }
        $people = signin_phone_users($pdo, $phone);
        if ($people === []) {
            if (function_exists('security_record_attempt')) {
                security_record_attempt($pdo, null, $phone, false, 'unknown_number', 'test_entry');
            }
            unset($_SESSION['test_entry']);
            return trainings_entry_idi('We couldn\'t find this number on HOD Lekki.');
        }
        $_SESSION['test_entry'] = ['item' => $itemId, 'uids' => array_map(static fn ($u) => (int) $u['id'], $people),
                                   'uid' => null, 'phone' => $phone, 'at' => time()];
        if (count($people) === 1) {
            $_SESSION['test_entry']['uid'] = (int) $people[0]['id'];
            return trainings_entry_route($pdo, $people[0], $ip);
        }
        return trainings_entry_reply('choose', ['people' => array_map(static fn ($u, $i) => ['i' => $i,
            'name' => trim((string) $u['first_name'] . ' ' . mb_substr((string) $u['last_name'], 0, 1) . ((string) $u['last_name'] !== '' ? '.' : ''))],
            $people, array_keys($people))]);
    }

    $s = trainings_entry_state();
    if ($s === null) {
        return $expired;
    }
    $itemId = (int) $s['item'];

    if ($do === 'choose') {
        $uid = $s['uids'][(int) ($in['i'] ?? -1)] ?? null;
        $user = $uid !== null ? trainings_entry_user($pdo, (int) $uid) : null;
        if ($user === null) {
            return $expired;
        }
        trainings_entry_put(['uid' => (int) $uid]);
        return trainings_entry_route($pdo, $user, $ip);
    }

    $user = !empty($s['uid']) ? trainings_entry_user($pdo, (int) $s['uid']) : null;
    if ($user === null) {
        return $expired;
    }
    $uid = (int) $user['id'];
    $first = trainings_entry_first($user);
    $mode = (string) ($s['mode'] ?? '');

    switch ($do) {
        case 'signin':
            $password = (string) ($in['password'] ?? '');
            if ($password === '') {
                return $fail('Please type your password.', 'INVALID', 'password');
            }
            $security = function_exists('security_schema_ready') && security_schema_ready($pdo);
            $check = signin_check_password($pdo, [$user], $password, (string) $s['phone'], $security);
            if (empty($check['ok'])) {
                return $fail((string) ($check['message'] ?? '') === SIGNIN_WRONG ? 'That password isn\'t right. Try again, or tap "I can\'t remember".'
                    : (string) $check['message'], 'WRONG', 'password');
            }
            if (!trainings_entry_open_account($check['user'])) {
                return trainings_entry_idi('This account is on hold.');
            }
            $signIn($check['user'], 'password');
            return trainings_entry_after_sign_in($pdo, $check['user'], $itemId, $ip);

        case 'forgot':
            // "I can't remember": a code to their email; none on record, they type one (with their birthday).
            trainings_entry_put(['mode' => 'reset', 'bday' => false]);
            $real = trainings_entry_real_email($pdo, $uid);
            if ($real !== null) {
                return trainings_entry_send($pdo, $user, $real, $ip);
            }
            // Staff too (owner, 22 Nov: no dead ends); a staff reset alerts IDI and their devices (new_password).
            return trainings_entry_reply('email', ['name' => $first, 'reset' => true]);

        case 'old_email':
            // "I don't use this email": they give the one they use. With a password at stake, their birthday too.
            trainings_entry_put(['bday' => false, 'target' => null, 'replace' => true]);
            return trainings_entry_reply('email', ['name' => $first, 'replace' => true, 'activate' => $mode === 'activate']);

        case 'email':
            $raw = trim((string) ($in['email'] ?? ''));
            $email = email_normalize($raw);
            if (trainings_entry_email_taken($pdo, $email, $uid)) {
                // Families share one Gmail; an address belongs to one account (owner: ask for a different one).
                return $fail(TRAININGS_ENTRY_TAKEN, 'TAKEN', 'email');
            }
            if (($problem = email_address_problem($email, $pdo, $uid)) !== null) {
                return $fail($problem, 'INVALID', 'email');
            }
            if (empty($in['as_typed']) && ($hint = email_typo_hint($email)) !== null) {
                return trainings_entry_reply('typo', ['typed' => $email, 'suggest' => $hint]);
            }
            trainings_entry_put(['typed_email' => $email]);
            // Resetting a password through an address not on record: their birthday first.
            if (!empty($user['password_hash']) && $mode === 'reset') {
                return trainings_entry_reply('birthday', ['name' => $first]);
            }
            return trainings_entry_send($pdo, $user, $email, $ip, true);

        case 'birthday':
        case 'birthday_update':
            if ($mode !== 'reset' || empty($s['typed_email'])) {
                return $expired;
            }
            $b = $do === 'birthday' ? trainings_entry_parse_birthday((string) ($in['birthday'] ?? '')) : ($s['said_birthday'] ?? null);
            if ($b === null) {
                return $fail('I couldn\'t read that date. Try it like 12 March.', 'INVALID', 'birthday');
            }
            $onRecord = trainings_entry_birthday_on_record($user);
            if ($do === 'birthday' && $onRecord !== null && $onRecord !== $b) {
                trainings_entry_put(['said_birthday' => $b]);
                return trainings_entry_reply('birthday_mismatch', ['said' => trainings_entry_birthday_text($b)]);
            }
            if ($onRecord !== $b) {
                trainings_entry_save_birthday($pdo, $user, $b);   // captured, or replaced (owner: replace freely)
                if (function_exists('security_log')) {
                    security_log($pdo, 'birthday_set_test_entry', $uid, 'Birthday ' . ($onRecord === null ? 'added' : 'changed') . ' while resetting a password from a test QR code.', $uid, trainings_entry_name($user));
                }
            }
            trainings_entry_put(['bday' => true]);
            return trainings_entry_send($pdo, $user, (string) $s['typed_email'], $ip, true);

        case 'resend':
            if (empty($s['target'])) {
                return $expired;
            }
            if ($mode === 'reset' && !empty($s['typed']) && empty($s['bday']) && !empty($user['password_hash'])) {
                return $expired;
            }
            $r = trainings_entry_send($pdo, $user, (string) $s['target'], $ip, !empty($s['typed']));
            return ($r['note'] ?? null) === 'already' ? $fail('A code is already on its way. Give it a minute.', 'TOO_SOON') : $r;

        case 'code':
            if (empty($s['target'])) {
                return $expired;
            }
            $check = auth_code_check($pdo, $uid, (string) ($s['purpose'] ?? 'reset'), (string) ($in['code'] ?? ''));
            if (empty($check['ok'])) {
                return $fail((string) $check['message'], (string) $check['code'], 'code');
            }
            if (!trainings_entry_activate_email($pdo, $uid, (string) $check['email'])) {
                return $fail(TRAININGS_ENTRY_TAKEN, 'TAKEN', 'email');   // taken by someone else meanwhile: another one, please
            }
            if (!empty($s['replace'])) {
                trainings_entry_replace_on_file($pdo, $uid, (string) $check['email']);   // "I don't use this email": the new one is theirs
            }
            if ($mode === 'activate') {
                return trainings_entry_reply('go', ['go' => exam_url_take($itemId), 'name' => $first]);
            }
            trainings_entry_put(['verified' => $uid, 'verified_at' => time()]);
            return trainings_entry_reply('new_password', ['name' => $first, 'first' => empty($user['password_hash'])]);

        case 'no_email':
            // "I don't have an email": a first-timer chooses a password on the number alone (as the first-time set-up
            // page allows); a signed-in person goes on to the test. Never for a reset.
            if ($mode === 'activate' && (int) ($_SESSION['user_id'] ?? 0) === $uid) {
                email_prompt_skip($pdo, $uid);
                email_prompt_forget();
                return trainings_entry_reply('go', ['go' => exam_url_take($itemId), 'name' => $first]);
            }
            if ($mode === 'first' && empty($user['password_hash'])) {
                trainings_entry_put(['verified' => $uid, 'verified_at' => time(), 'no_email' => true]);
                return trainings_entry_reply('new_password', ['name' => $first, 'first' => true]);
            }
            return $fail('We need your email to send you a code.', 'INVALID', 'email');

        case 'new_password':
            if ((int) ($s['verified'] ?? 0) !== $uid || (int) ($s['verified_at'] ?? 0) < time() - TRAININGS_ENTRY_VERIFIED_TTL) {
                return $expired;
            }
            if ($bad = trainings_entry_password_problem((string) ($in['password'] ?? ''), (string) ($in['confirm'] ?? ''), $user)) {
                return $bad;
            }
            if (!trainings_entry_open_account($user)) {
                return trainings_entry_idi('This account is on hold.');
            }
            $hadPassword = !empty($user['password_hash']);
            trainings_entry_set_password($pdo, $uid, (string) $in['password']);
            if ($hadPassword && function_exists('security_revoke_sessions')) {
                security_revoke_sessions($pdo, $uid, $uid, 'Password reset by email code');
            }
            if (function_exists('security_log')) {
                security_log($pdo, $hadPassword ? 'password_reset_by_code' : 'password_first_set', $uid,
                    ($hadPassword ? 'Password changed with an emailed code' : 'First password set') . ' from a test QR code'
                    . (!empty($s['no_email']) ? ' (no email)' : '') . '.', $uid, trainings_entry_name($user));
            }
            $to = email_identity_ready($pdo) ? member_confirmed_email($pdo, $uid) : null;
            if ($hadPassword && $to !== null) {
                email_send_doc($pdo, email_password_changed_doc(), $user + ['email' => $to], [
                    'lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_changed', 'to_email' => $to, 'now' => true,
                ]);
            }
            if (trainings_entry_is_staff($pdo, $uid)) {
                trainings_entry_staff_alert($pdo, $user, $hadPassword);   // owner: same way in for staff, with an alert
            }
            unset($_SESSION['test_entry']['verified']);
            $signIn(trainings_entry_user($pdo, $uid) ?? $user, $hadPassword ? 'password reset' : 'first password');
            return trainings_entry_reply('go', ['go' => exam_url_take($itemId), 'name' => $first]);
    }
    return $fail('Unknown action.');
}

// ---------------------------------------------------------------------------
// After the test: the result's "Confirm your email" card (for anyone still without a confirmed email)
// ---------------------------------------------------------------------------

/**
 * Null when their email is confirmed (or they said they have none); ['state' => 'confirm', 'to' => masked] when one
 * waits; ['state' => 'add'] when HOD has none.
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
        return ['state' => 'confirm', 'to' => trainings_entry_mask((string) $waiting)];
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
    $typed = trim((string) ($in['email'] ?? ''));
    if ($st['confirmed'] !== null && $typed === '') {
        return ['status' => 'success', 'done' => true, 'message' => 'Your email is confirmed.'];
    }
    $to = $typed !== '' ? $typed : ($st['pending'] ?? $st['on_file']);
    if ($to === null || $to === '') {
        return ['status' => 'error', 'message' => 'Please type your email.', 'field' => 'email'];
    }
    $to = email_normalize($to);
    if (trainings_entry_email_taken($pdo, $to, $uid)) {
        return ['status' => 'error', 'message' => TRAININGS_ENTRY_TAKEN, 'code' => 'TAKEN', 'field' => 'email'];
    }
    if (($problem = email_address_problem($to, $pdo, $uid)) !== null) {
        return ['status' => 'error', 'message' => $problem, 'field' => 'email'];
    }
    $r = auth_code_send($pdo, $user, 'confirm', $to, $ip);
    return !empty($r['ok']) ? ['status' => 'success', 'to' => trainings_entry_mask($to), 'mail' => trainings_entry_mail_link($to)]
        : ['status' => 'error', 'message' => (string) $r['message'], 'code' => $r['code']];
}

/** The code is right: confirm the address it went to (through the email identity flow, as its link would). */
function trainings_entry_confirm_check(PDO $pdo, array $user, string $code): array
{
    $uid = (int) $user['id'];
    $check = auth_code_check($pdo, $uid, 'confirm', $code);
    if (empty($check['ok'])) {
        return ['status' => 'error', 'message' => (string) $check['message'], 'code' => $check['code'], 'field' => 'code'];
    }
    return trainings_entry_activate_email($pdo, $uid, (string) $check['email'])
        ? ['status' => 'success', 'done' => true, 'message' => 'Your email is confirmed.']
        : ['status' => 'error', 'message' => TRAININGS_ENTRY_TAKEN, 'code' => 'TAKEN'];
}
