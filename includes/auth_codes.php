<?php
// /includes/auth_codes.php
//
// Six-digit codes sent by email, typed on the same screen (owner, 21 Nov 2026: the QR entry to a standalone test).
//   reset    a forgotten password: to the person's CONFIRMED personal email only;
//   confirm  after a test: to the email they gave us, which it confirms.
// Table: auth_email_codes (db/migrations/20261223090000_auth_email_codes.sql). The code is never stored, only its
// password_hash(). A code works once, for AUTH_CODE_TTL_MINUTES, and dies after AUTH_CODE_MAX_TRIES wrong tries; a new
// one replaces the last. Sends: one a minute per person, purpose and address, AUTH_CODE_PER_HOUR an hour per person,
// AUTH_CODE_PER_IP_HOUR an hour per IP. The email goes through the transactional lane (includes/campaigns/outbox.php).
// Needs includes/campaigns/bootstrap.php. Tests: tests/trainings/run.php, "QR entry".

const AUTH_CODE_PURPOSES = ['reset', 'confirm'];
const AUTH_CODE_TTL_MINUTES = 15;
const AUTH_CODE_MAX_TRIES = 5;
const AUTH_CODE_GAP_SECONDS = 60;
const AUTH_CODE_PER_HOUR = 5;
const AUTH_CODE_PER_IP_HOUR = 20;

function auth_codes_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM auth_email_codes LIMIT 0')->closeCursor();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Send a new code to $email for $user (a users row). Earlier codes of the same purpose stop working.
 * @return array{ok:bool, code?:string, message?:string, to?:string}
 *   code (on failure): NOT_READY | TOO_SOON | RATE_LIMITED | NOT_SENT
 */
function auth_code_send(PDO $pdo, array $user, string $purpose, string $email, string $ip): array
{
    if (!in_array($purpose, AUTH_CODE_PURPOSES, true)) {
        throw new InvalidArgumentException('Unknown code purpose: ' . $purpose);
    }
    if (!auth_codes_ready($pdo)) {
        return ['ok' => false, 'code' => 'NOT_READY', 'message' => 'This opens after the next update. Ask Information and Data Insights (IDI).'];
    }
    $uid = (int) $user['id'];
    $now = campaigns_now();
    // One a minute to the same address (a new address, after "I don't use this email", gets its code at once).
    $stmt = $pdo->prepare('SELECT MAX(created_at) FROM auth_email_codes WHERE user_id = ? AND purpose = ? AND email = ?');
    $stmt->execute([$uid, $purpose, $email]);
    $last = $stmt->fetchColumn();
    if ($last && $now->getTimestamp() - campaigns_dt((string) $last)->getTimestamp() < AUTH_CODE_GAP_SECONDS) {
        return ['ok' => false, 'code' => 'TOO_SOON', 'message' => 'A code is on its way. Wait a minute.'];
    }
    $hourAgo = campaigns_sql_dt($now->modify('-1 hour'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_email_codes WHERE user_id = ? AND created_at >= ?');
    $stmt->execute([$uid, $hourAgo]);
    $mine = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_email_codes WHERE ip = ? AND created_at >= ?');
    $stmt->execute([$ip, $hourAgo]);
    if ($mine >= AUTH_CODE_PER_HOUR || ($ip !== '' && (int) $stmt->fetchColumn() >= AUTH_CODE_PER_IP_HOUR)) {
        return ['ok' => false, 'code' => 'RATE_LIMITED', 'message' => 'Too many codes. Try again in an hour.'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $nowSql = campaigns_sql_dt($now);
    $pdo->prepare('UPDATE auth_email_codes SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$nowSql, $uid, $purpose]);
    $pdo->prepare('INSERT INTO auth_email_codes (user_id, purpose, email, code_hash, tries, ip, created_at, expires_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)')
        ->execute([$uid, $purpose, $email, password_hash($code, PASSWORD_DEFAULT), $ip !== '' ? substr($ip, 0, 45) : null, $nowSql,
                   campaigns_sql_dt($now->modify('+' . AUTH_CODE_TTL_MINUTES . ' minutes'))]);
    $queued = email_send_doc($pdo, auth_code_doc($code, $purpose), $user + ['email' => $email], [
        'lane' => 'transactional', 'purpose' => 'account', 'flow' => $purpose === 'reset' ? 'reset_code' : 'confirm_code',
        'to_email' => $email, 'now' => true,
    ]);
    if (empty($queued['ok'])) {
        error_log('[auth] code email for user ' . $uid . ' not queued: ' . ($queued['message'] ?? $queued['code'] ?? 'unknown'));
        return ['ok' => false, 'code' => 'NOT_SENT', 'message' => 'The email could not be sent. Try again.'];
    }
    return ['ok' => true, 'to' => email_mask($email)];
}

/**
 * Check a typed code. Right: it is used up and the email it went to comes back. Wrong: one try fewer.
 * @return array{ok:bool, email?:string, code?:string, message?:string}  code: NONE | EXPIRED | WRONG | DEAD
 */
function auth_code_check(PDO $pdo, int $userId, string $purpose, string $typed): array
{
    if (!auth_codes_ready($pdo)) {
        return ['ok' => false, 'code' => 'NONE', 'message' => 'Ask for a new code.'];
    }
    $typed = (string) preg_replace('/\D/', '', $typed);
    $stmt = $pdo->prepare('SELECT * FROM auth_email_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'code' => 'NONE', 'message' => 'Ask for a new code.'];
    }
    $now = campaigns_now();
    if (campaigns_dt((string) $row['expires_at']) < $now) {
        return ['ok' => false, 'code' => 'EXPIRED', 'message' => 'That code has expired. Ask for a new one.'];
    }
    if ((int) $row['tries'] >= AUTH_CODE_MAX_TRIES) {
        return ['ok' => false, 'code' => 'DEAD', 'message' => 'Too many tries. Ask for a new code.'];
    }
    if (strlen($typed) !== 6 || !password_verify($typed, (string) $row['code_hash'])) {
        $pdo->prepare('UPDATE auth_email_codes SET tries = tries + 1 WHERE id = ?')->execute([(int) $row['id']]);
        $left = AUTH_CODE_MAX_TRIES - (int) $row['tries'] - 1;
        return ['ok' => false, 'code' => $left > 0 ? 'WRONG' : 'DEAD',
                'message' => $left > 0 ? 'That code is not right. Try again.' : 'Too many tries. Ask for a new code.'];
    }
    // Use it first: two presses at once cannot both win.
    $use = $pdo->prepare('UPDATE auth_email_codes SET used_at = ? WHERE id = ? AND used_at IS NULL');
    $use->execute([campaigns_sql_dt($now), (int) $row['id']]);
    if ($use->rowCount() !== 1) {
        return ['ok' => false, 'code' => 'NONE', 'message' => 'Ask for a new code.'];
    }
    return ['ok' => true, 'email' => (string) $row['email']];
}

/** The code email: the six digits, large, and what they are for. */
function auth_code_doc(string $code, string $purpose): array
{
    $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);
    $what = $purpose === 'reset' ? 'to choose a new password' : 'to confirm this email address';
    return [
        'v' => 1,
        'subject' => 'Your code: ' . $spaced,
        'preheader' => 'It works for ' . AUTH_CODE_TTL_MINUTES . ' minutes.',
        'blocks' => [
            email_doc_paragraphs('Hi {{first_name}},', 'Here is your code ' . $what . ' on Household of David Lekki:'),
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => $spaced],
            email_doc_paragraphs('It works once, for ' . AUTH_CODE_TTL_MINUTES . ' minutes. If you did not ask for it, ignore this email: nothing changes.'),
            ['type' => 'footer'],
        ],
    ];
}
