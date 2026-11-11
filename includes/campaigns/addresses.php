<?php
// /includes/campaigns/addresses.php
//
// A person's PERSONAL email: the address password-reset links and church news
// go to. It is its own record (email_addresses), never users.email, which is
// the members' sign-in name and which Embrace, Congregation and Profile all
// overwrite.
//
// Rules (the only validator; every screen calls email_address_problem()):
//   - never @hodlekki.org (or a subdomain): a reset link must reach someone who
//     is locked out of the ERP, and an ERP mailbox is behind that same sign-in;
//   - never @hodlc.com: those are the church-issued sign-in names;
//   - one confirmed address per person and one person per confirmed address,
//     enforced by the UNIQUE keys on confirmed_user_id / confirmed_email.
//
// An address becomes confirmed only through the link sent to it: the page at
// the link shows one button that POSTs (mail scanners open links; they do not
// press buttons), and that POST calls email_capture_confirm().

const EMAIL_REFUSED_DOMAINS     = ['hodlekki.org', 'hodlc.com'];
const EMAIL_CONFIRM_TTL_DAYS    = 7;
const EMAIL_LINKS_PER_PERSON_DAY  = 5;
const EMAIL_LINKS_PER_ADDRESS_DAY = 3;
const EMAIL_RESEND_GAP_SECONDS  = 60;
const EMAIL_SOURCES = ['connect', 'embrace', 'signin_prompt', 'profile', 'staff', 'import'];
// The consent method each place records when it asks in the same form.
const EMAIL_SOURCE_CONSENT_METHOD = [
    'connect' => 'connect_form', 'embrace' => 'embrace_entry', 'signin_prompt' => 'signin_prompt',
    'profile' => 'profile', 'staff' => 'staff', 'import' => 'import',
];

// Common slips on the domains people actually use here. Shown as a hint, never
// applied silently.
const EMAIL_DOMAIN_TYPOS = [
    'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gamil.com' => 'gmail.com',
    'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmaill.com' => 'gmail.com',
    'gnail.com' => 'gmail.com', 'yahoo.con' => 'yahoo.com', 'yaho.com' => 'yahoo.com',
    'yahooo.com' => 'yahoo.com', 'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com',
    'outlok.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'iclod.com' => 'icloud.com',
];

function email_normalize(?string $raw): string
{
    return strtolower(trim((string) $raw));
}

function email_domain(string $email): string
{
    $at = strrpos($email, '@');
    return $at === false ? '' : strtolower(substr($email, $at + 1));
}

/** A church-issued address: @hodlekki.org (or a subdomain) or @hodlc.com. */
function email_is_church_domain(string $email): bool
{
    $domain = email_domain(email_normalize($email));
    foreach (EMAIL_REFUSED_DOMAINS as $refused) {
        if ($domain === $refused || str_ends_with($domain, '.' . $refused)) {
            return true;
        }
    }
    return false;
}

/** Syntax only: a valid address with a real-looking domain. */
function email_is_valid_syntax(string $email): bool
{
    if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    return (bool) preg_match('/\.[a-z]{2,}$/', email_domain($email));
}

/**
 * Why this address cannot be someone's personal email, in words for the
 * person, or null when it can. With $pdo it also refuses an address another
 * person has already confirmed.
 */
function email_address_problem(string $raw, ?PDO $pdo = null, ?int $userId = null): ?string
{
    $email = email_normalize($raw);
    if ($email === '') {
        return 'Enter your email.';
    }
    if (strlen($email) > 190) {
        return 'That email is too long.';
    }
    if (!email_is_valid_syntax($email)) {
        return "That email doesn't look right.";
    }
    $domain = email_domain($email);
    if ($domain === 'hodlc.com') {
        return 'Use your own email, not a hodlc.com sign-in name.';
    }
    if (email_is_church_domain($email)) {
        return 'Use your own email, not a church one.';
    }
    if ($pdo !== null && ($owner = member_by_confirmed_email($pdo, $email)) !== null && $owner !== $userId) {
        return 'This email is on another account. Ask the church office.';
    }
    return null;
}

/** "Did you mean name@gmail.com?" — or null. */
function email_typo_hint(string $raw): ?string
{
    $email = email_normalize($raw);
    $domain = email_domain($email);
    if (!isset(EMAIL_DOMAIN_TYPOS[$domain])) {
        return null;
    }
    return substr($email, 0, strrpos($email, '@') + 1) . EMAIL_DOMAIN_TYPOS[$domain];
}

/** A users.email value that is a real personal address (not a sign-in name). */
function email_looks_personal(?string $email): bool
{
    $email = email_normalize($email);
    return $email !== '' && email_is_valid_syntax($email) && !email_is_church_domain($email);
}

function email_confirm_url(string $token): string
{
    return site_url('/email/confirm.php?t=' . rawurlencode($token));
}

// ---------------------------------------------------------------------------
// Lookups (the mailbox, the prompt, Profile and sign-in call these)
// ---------------------------------------------------------------------------

/** The person's confirmed personal email, or null. */
function member_confirmed_email(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0 || !campaigns_table_exists($pdo, 'email_addresses')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT confirmed_email FROM email_addresses WHERE confirmed_user_id = ?');
    $stmt->execute([$userId]);
    $email = $stmt->fetchColumn();
    return $email === false || $email === null ? null : (string) $email;
}

/** Whose confirmed personal email is this? */
function member_by_confirmed_email(PDO $pdo, string $email): ?int
{
    $email = email_normalize($email);
    if ($email === '' || !campaigns_table_exists($pdo, 'email_addresses')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT confirmed_user_id FROM email_addresses WHERE confirmed_email = ?');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    return $id === false || $id === null ? null : (int) $id;
}

/**
 * Where a person stands, for the prompt and Profile:
 *   confirmed  the confirmed address (+ confirmed_at)
 *   pending    an address waiting for its link (+ sent_at, expires_at)
 *   on_file    users.email when it is a real personal address nobody confirmed
 *
 * @return array{confirmed:?string, confirmed_at:?string, pending:?string, pending_sent_at:?string, pending_expires_at:?string, on_file:?string}
 */
function email_address_state(PDO $pdo, int $userId): array
{
    $state = [
        'confirmed' => null, 'confirmed_at' => null,
        'pending' => null, 'pending_sent_at' => null, 'pending_expires_at' => null,
        'on_file' => null,
    ];
    if ($userId <= 0) {
        return $state;
    }
    if (campaigns_table_exists($pdo, 'email_addresses')) {
        $stmt = $pdo->prepare("SELECT email, status, confirmed_at, last_sent_at, token_expires_at
                                 FROM email_addresses
                                WHERE user_id = ? AND status IN ('confirmed', 'pending')
                                ORDER BY id DESC");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'confirmed' && $state['confirmed'] === null) {
                $state['confirmed'] = (string) $row['email'];
                $state['confirmed_at'] = $row['confirmed_at'];
            } elseif ($row['status'] === 'pending' && $state['pending'] === null) {
                $state['pending'] = (string) $row['email'];
                $state['pending_sent_at'] = $row['last_sent_at'];
                $state['pending_expires_at'] = $row['token_expires_at'];
            }
        }
    }
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $onFile = email_normalize((string) ($stmt->fetchColumn() ?: ''));
    if (email_looks_personal($onFile) && $onFile !== $state['confirmed']) {
        $state['on_file'] = $onFile;
    }
    return $state;
}

// ---------------------------------------------------------------------------
// Capture and confirmation
// ---------------------------------------------------------------------------

/**
 * Start (or restart) confirming $email as $userId's personal address.
 *
 * Creates a pending row with a fresh link token (only its sha256 is stored),
 * cancels the person's other pending row, logs consent when the same form
 * asked for it, and queues the confirmation email in the transactional lane.
 *
 * $opts:
 *   consent  ['granted' => bool, 'method' => …, 'text' => wording shown,
 *             'recorded_by' => ?int, 'ip' => ?string, 'user_agent' => ?string]
 *   send     false to skip the email (tests, imports)
 *   ip, user_agent
 *
 * @return array{ok:bool, code?:string, message?:string, address_id?:int, email?:string,
 *               token?:string, link?:string, outbox_id?:?int, already_confirmed?:bool}
 */
function email_capture_start(PDO $pdo, int $userId, string $rawEmail, string $source, ?int $byUserId = null, array $opts = []): array
{
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'Email is being set up. Try again later.'];
    }
    if (!in_array($source, EMAIL_SOURCES, true)) {
        throw new InvalidArgumentException('Unknown email source: ' . $source);
    }
    $email = email_normalize($rawEmail);
    if (($problem = email_address_problem($email, $pdo, $userId)) !== null) {
        return ['ok' => false, 'code' => 'INVALID_EMAIL', 'message' => $problem];
    }
    $person = email_person($pdo, $userId);
    if ($person === null) {
        return ['ok' => false, 'code' => 'NO_PERSON', 'message' => 'That person was not found.'];
    }

    $now = campaigns_now();
    $nowSql = campaigns_sql_dt($now);

    if (!empty($opts['consent']) && is_array($opts['consent']) && !empty($opts['consent']['granted'])) {
        $method = (string) ($opts['consent']['method'] ?? (EMAIL_SOURCE_CONSENT_METHOD[$source] ?? 'profile'));
        email_consent_record($pdo, $userId, 'granted', $method, [
            'email'       => $email,
            'text'        => (string) ($opts['consent']['text'] ?? EMAIL_CONSENT_NEWS_TEXT),
            'recorded_by' => $opts['consent']['recorded_by'] ?? null,
            'ip'          => $opts['consent']['ip'] ?? ($opts['ip'] ?? null),
            'user_agent'  => $opts['consent']['user_agent'] ?? ($opts['user_agent'] ?? null),
        ]);
    }

    if (member_confirmed_email($pdo, $userId) === $email) {
        return ['ok' => true, 'already_confirmed' => true, 'email' => $email];
    }

    // Limits: links are not free to the person on the other end either. Counted
    // from the links actually queued in the last 24 hours.
    $since = campaigns_sql_dt($now->modify('-1 day'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE flow = 'confirm_email' AND user_id = ? AND created_at >= ?");
    $stmt->execute([$userId, $since]);
    $byPerson = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE flow = 'confirm_email' AND to_email = ? AND created_at >= ?");
    $stmt->execute([$email, $since]);
    $byAddress = (int) $stmt->fetchColumn();
    if ($byPerson >= EMAIL_LINKS_PER_PERSON_DAY || $byAddress >= EMAIL_LINKS_PER_ADDRESS_DAY) {
        return ['ok' => false, 'code' => 'RATE_LIMITED',
                'message' => 'Too many links today. Try again tomorrow.'];
    }
    // The public Connect card can name any address: limit links per requester
    // and in total, so it can never be used to mail strangers or to use up the
    // domain's budget for real confirmations and resets.
    if ($source === 'connect' && ($opts['send'] ?? true) !== false) {
        $why = email_connect_throttle($pdo, (string) ($opts['ip'] ?? ''), $now);
        if ($why !== null) {
            return ['ok' => false, 'code' => 'THROTTLED', 'message' => $why];
        }
    }

    $token = campaigns_token(32);
    $hash = hash('sha256', $token);
    $expires = campaigns_sql_dt($now->modify('+' . EMAIL_CONFIRM_TTL_DAYS . ' days'));

    // Same address already pending: give it a new link instead of a new row.
    $stmt = $pdo->prepare("SELECT id, last_sent_at FROM email_addresses WHERE user_id = ? AND email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, $email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existing !== null && $existing['last_sent_at'] !== null
        && $now->getTimestamp() - campaigns_dt((string) $existing['last_sent_at'])->getTimestamp() < EMAIL_RESEND_GAP_SECONDS) {
        return ['ok' => false, 'code' => 'TOO_SOON', 'message' => 'A link is on its way. Wait a minute.'];
    }

    if ($existing !== null) {
        $addressId = (int) $existing['id'];
        $pdo->prepare('UPDATE email_addresses SET token_hash = ?, token_expires_at = ?, sends = sends + 1, last_sent_at = ? WHERE id = ?')
            ->execute([$hash, $expires, $nowSql, $addressId]);
    } else {
        $pdo->prepare("UPDATE email_addresses SET status = 'cancelled', token_hash = NULL, ended_at = ? WHERE user_id = ? AND status = 'pending'")
            ->execute([$nowSql, $userId]);
        $pdo->prepare('INSERT INTO email_addresses (user_id, email, status, source, token_hash, token_expires_at, sends, last_sent_at, created_by, created_at)
                       VALUES (?, ?, \'pending\', ?, ?, ?, 1, ?, ?, ?)')
            ->execute([$userId, $email, $source, $hash, $expires, $nowSql, $byUserId, $nowSql]);
        $addressId = (int) $pdo->lastInsertId();
    }

    $link = email_confirm_url($token);
    $outboxId = null;
    if (($opts['send'] ?? true) !== false) {
        $doc = email_confirmation_doc($link);
        $queued = email_send_doc($pdo, $doc, $person + ['email' => $email], [
            'lane'       => 'transactional',
            'purpose'    => 'account',
            'flow'       => 'confirm_email',
            'to_email'   => $email,
            'created_by' => $byUserId,
            'now'        => true,
        ]);
        $outboxId = $queued['id'] ?? null;
        if (!empty($queued['ok']) && $source === 'connect') {
            email_connect_throttle_note($pdo, (string) ($opts['ip'] ?? ''), $now);
        }
        if (empty($queued['ok'])) {
            error_log('[email] could not queue the confirmation email for user ' . $userId . ': ' . ($queued['message'] ?? $queued['code'] ?? 'unknown'));
        }
    }

    return ['ok' => true, 'address_id' => $addressId, 'email' => $email, 'token' => $token,
            'link' => $link, 'outbox_id' => $outboxId];
}

/**
 * The pending address a link points at, for the confirm page's GET (it shows
 * the address and one button). Null when the link is unknown.
 *
 * @return array{state:string, email:string, user_id:int}|null state: pending|confirmed|expired|used
 */
function email_capture_peek(PDO $pdo, string $token): ?array
{
    if ($token === '' || !campaigns_table_exists($pdo, 'email_addresses')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT user_id, email, status, token_expires_at FROM email_addresses WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $state = (string) $row['status'];
    if ($state === 'pending' && $row['token_expires_at'] !== null
        && campaigns_dt((string) $row['token_expires_at']) < campaigns_now()) {
        $state = 'expired';
    } elseif (!in_array($state, ['pending', 'confirmed'], true)) {
        $state = 'used';
    }
    return ['state' => $state, 'email' => (string) $row['email'], 'user_id' => (int) $row['user_id']];
}

/**
 * Confirm the address a link points at. One transaction: the person's earlier
 * confirmed address becomes 'replaced', this one 'confirmed', their other
 * pending rows 'cancelled'. The UNIQUE keys decide any race, so two people can
 * never both hold one address.
 *
 * @return array{ok:bool, code?:string, user_id?:int, email?:string, already?:bool}
 *   code: INVALID | EXPIRED | USED | TAKEN | REFUSED | FEATURE_NOT_READY
 */
function email_capture_confirm(PDO $pdo, string $token, ?string $ip = null): array
{
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY'];
    }
    $stmt = $pdo->prepare('SELECT * FROM email_addresses WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'code' => 'INVALID'];
    }
    $userId = (int) $row['user_id'];
    $email = (string) $row['email'];
    if ($row['status'] === 'confirmed') {
        return ['ok' => true, 'already' => true, 'user_id' => $userId, 'email' => $email];
    }
    if ($row['status'] !== 'pending') {
        return ['ok' => false, 'code' => 'USED', 'user_id' => $userId, 'email' => $email];
    }
    $now = campaigns_now();
    if ($row['token_expires_at'] !== null && campaigns_dt((string) $row['token_expires_at']) < $now) {
        $pdo->prepare("UPDATE email_addresses SET status = 'expired', ended_at = ? WHERE id = ? AND status = 'pending'")
            ->execute([campaigns_sql_dt($now), (int) $row['id']]);
        return ['ok' => false, 'code' => 'EXPIRED', 'user_id' => $userId, 'email' => $email];
    }
    if (email_is_church_domain($email) || !email_is_valid_syntax($email)) {
        return ['ok' => false, 'code' => 'REFUSED', 'user_id' => $userId, 'email' => $email];
    }

    $nowSql = campaigns_sql_dt($now);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare("UPDATE email_addresses
                          SET status = 'replaced', confirmed_user_id = NULL, confirmed_email = NULL, ended_at = ?
                        WHERE confirmed_user_id = ?")
            ->execute([$nowSql, $userId]);
        $upd = $pdo->prepare("UPDATE email_addresses
                                 SET status = 'confirmed', confirmed_user_id = ?, confirmed_email = ?,
                                     confirmed_at = ?, confirmed_ip = ?
                               WHERE id = ? AND status = 'pending'");
        $upd->execute([$userId, $email, $nowSql, $ip !== null ? campaigns_cut($ip, 45) : null, (int) $row['id']]);
        if ($upd->rowCount() !== 1) {
            // A double click: the other request confirmed it a moment ago.
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $again = $pdo->prepare('SELECT status FROM email_addresses WHERE id = ?');
            $again->execute([(int) $row['id']]);
            return $again->fetchColumn() === 'confirmed'
                ? ['ok' => true, 'already' => true, 'user_id' => $userId, 'email' => $email]
                : ['ok' => false, 'code' => 'USED', 'user_id' => $userId, 'email' => $email];
        }
        $pdo->prepare("UPDATE email_addresses SET status = 'cancelled', token_hash = NULL, ended_at = ?
                        WHERE user_id = ? AND status = 'pending' AND id <> ?")
            ->execute([$nowSql, $userId, (int) $row['id']]);
        if ($own) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (campaigns_is_duplicate_key($e)) {
            return ['ok' => false, 'code' => 'TAKEN', 'user_id' => $userId, 'email' => $email];
        }
        throw $e;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    email_sync_users_email($pdo, $userId, $email);

    return ['ok' => true, 'user_id' => $userId, 'email' => $email];
}

/**
 * Copy a newly confirmed address into users.email, as a convenience for the
 * screens that show it — but ONLY when users.email is empty. Whatever is there
 * may be what the person signs in with (a @hodlc.com name, or a personal
 * address); changing it would change how they sign in, which is the sign-in
 * change's job (docs/EMAIL.md). Nothing depends on this copy.
 */
function email_sync_users_email(PDO $pdo, int $userId, string $email): void
{
    try {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $current = email_normalize((string) ($stmt->fetchColumn() ?: ''));
        // Only ever fill an EMPTY users.email: whatever is there may be the
        // address someone signs in with (a personal one included).
        if ($current !== '') {
            return;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? AND id <> ?');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
    } catch (PDOException $e) {
        // users.email is UNIQUE: a race lost here only costs the convenience copy.
        if (!campaigns_is_duplicate_key($e)) {
            error_log('[email] users.email sync failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }
}

/**
 * A form has just saved an email for this person (Connect, an Embrace worker
 * adding or editing a first timer). Starts confirmation unless that address is
 * already confirmed or already waiting for its link (saving a record again
 * never re-sends), and records a "yes" to church news when the form asked.
 * Best effort: the record is already saved, so this never throws.
 *
 * $evidence: ip, user_agent (the person's own form).
 */
function email_capture_after_save(PDO $pdo, int $personId, ?string $email, string $source, ?int $byUserId, bool $newsYes, array $evidence = []): void
{
    try {
        $email = email_normalize($email);
        if ($email === '' || $personId <= 0 || !campaigns_ready($pdo) || email_address_problem($email) !== null) {
            return;
        }
        // Visitor forms show a box that starts ticked: recorded as exactly that
        // ('prechecked', with the wording shown), by whoever saved the form. A
        // pre-ticked box never overrides an earlier "stop": a worker re-saving a
        // record must not quietly re-subscribe someone who unsubscribed.
        $staff = $source === 'embrace' || $source === 'staff';
        $visitorForm = in_array($source, ['connect', 'embrace'], true);
        $consent = $newsYes && email_consent_state($pdo, $personId) !== 'withdrawn' ? [
            'granted'     => true,
            'method'      => $visitorForm ? 'prechecked' : (EMAIL_SOURCE_CONSENT_METHOD[$source] ?? 'profile'),
            'text'        => $visitorForm ? EMAIL_CONSENT_VISITOR_TEXT : EMAIL_CONSENT_NEWS_TEXT,
            'recorded_by' => $staff ? $byUserId : null,
            'ip'          => $staff ? null : ($evidence['ip'] ?? null),
            'user_agent'  => $staff ? null : ($evidence['user_agent'] ?? null),
        ] : null;
        $state = email_address_state($pdo, $personId);
        if ($state['confirmed'] === $email || $state['pending'] === $email) {
            if ($consent !== null && email_consent_state($pdo, $personId) !== 'granted') {
                email_consent_record($pdo, $personId, 'granted', $consent['method'], $consent + ['email' => $email]);
            }
            return;
        }
        $r = email_capture_start($pdo, $personId, $email, $source, $byUserId, [
            'consent'    => $consent,
            'ip'         => $evidence['ip'] ?? null,
            'user_agent' => $evidence['user_agent'] ?? null,
        ]);
        if (empty($r['ok'])) {
            error_log('[email] capture after ' . $source . ' save for user ' . $personId . ': ' . ($r['code'] ?? '') . ' ' . ($r['message'] ?? ''));
        } elseif (function_exists('email_prompt_unskip')) {
            email_prompt_unskip($pdo, $personId);
        }
    } catch (Throwable $e) {
        error_log('[email] capture after ' . $source . ' save failed for user ' . $personId . ': ' . $e->getMessage());
    }
}

const EMAIL_CONNECT_LINKS_PER_IP_HOUR = 5;
const EMAIL_CONNECT_LINKS_PER_HOUR    = 20;

/**
 * Why a Connect-card link may not go now, or null. Per requester (hashed IP)
 * and in total per hour. Fails closed: no throttle table, no link.
 */
function email_connect_throttle(PDO $pdo, string $ip, DateTimeImmutable $now): ?string
{
    if (!campaigns_table_exists($pdo, 'email_link_throttle')) {
        return 'Email links from the Connect card are not set up yet.';
    }
    $stmt = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM email_link_throttle WHERE kind = 'connect' AND created_at >= ?) AS total,
            (SELECT COUNT(*) FROM email_link_throttle WHERE kind = 'connect' AND key_hash = ? AND created_at >= ?) AS mine");
    $since = campaigns_sql_dt($now->modify('-1 hour'));
    $stmt->execute([$since, hash('sha256', 'hod-connect-ip|' . $ip), $since]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $c['mine'] >= EMAIL_CONNECT_LINKS_PER_IP_HOUR) {
        return 'Too many confirmation links from this connection in the last hour.';
    }
    if ((int) $c['total'] >= EMAIL_CONNECT_LINKS_PER_HOUR) {
        return 'The hourly limit for confirmation links from the Connect card is reached.';
    }
    return null;
}

function email_connect_throttle_note(PDO $pdo, string $ip, DateTimeImmutable $now): void
{
    $pdo->prepare("DELETE FROM email_link_throttle WHERE created_at < ?")->execute([campaigns_sql_dt($now->modify('-1 day'))]);
    $pdo->prepare("INSERT INTO email_link_throttle (kind, key_hash, created_at) VALUES ('connect', ?, ?)")
        ->execute([hash('sha256', 'hod-connect-ip|' . $ip), campaigns_sql_dt($now)]);
}

/** Withdraw a pending address (the person changed their mind). */
function email_capture_cancel(PDO $pdo, int $userId): int
{
    if (!campaigns_table_exists($pdo, 'email_addresses')) {
        return 0;
    }
    $stmt = $pdo->prepare("UPDATE email_addresses SET status = 'cancelled', token_hash = NULL, ended_at = ?
                            WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([campaigns_sql_dt(campaigns_now()), $userId]);
    return $stmt->rowCount();
}

/** The fields the emails use, or null when the person does not exist. */
function email_person(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, phone, spiritual_status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** The confirmation email (account mail: no consent needed, no unsubscribe). */
function email_confirmation_doc(string $link): array
{
    return [
        'v' => 1,
        'subject' => 'Please confirm your email',
        'preheader' => 'One tap to confirm this is your email address.',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'Please confirm your email'],
            ['type' => 'text', 'align' => 'left', 'content' => [
                ['k' => 'p', 'r' => [['t' => 'Hi {{first_name}},']]],
                ['k' => 'p', 'r' => [
                    ['t' => 'Tap the button to confirm that '],
                    ['t' => '{{email}}', 'b' => 1],
                    ['t' => ' is your email address. Household of David Lekki will use it for your church account, including password reset links, and for church news if you said yes to it.'],
                ]],
            ]],
            ['type' => 'button', 'label' => 'Confirm my email', 'href' => $link, 'style' => 'primary', 'align' => 'left'],
            ['type' => 'text', 'align' => 'left', 'content' => [
                ['k' => 'p', 'r' => [['t' => 'The link works for ' . EMAIL_CONFIRM_TTL_DAYS . " days. If you did not ask for this, you can ignore this email and nothing will change."]]],
            ]],
            ['type' => 'footer'],
        ],
    ];
}
