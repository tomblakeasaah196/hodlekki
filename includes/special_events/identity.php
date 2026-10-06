<?php
// /includes/special_events/identity.php
//
// Who the person on the other end of the phone is (guide §10.3): phone
// normalisation, contacts keyed by phone, member recognition against `users`
// (read-only, D2), device cookies and the manage/transfer tokens.
//
// Nothing in here ever writes `users`. A member who tells us their name is
// different is recorded as a name correction on their registration, which the
// Studio lists for IDI to apply by hand (§10.3.2).

// --------------------------------------------------------------------------
// Phone numbers (§10.3.1)
// --------------------------------------------------------------------------

/**
 * Normalise a phone number the way the guide's four steps describe.
 *
 * Nigerian mobiles are delegated to sms_normalize_phone() so that the module
 * and SMS Studio can never disagree about what "0803 123 4567" means. Other
 * numbers are kept in E.164 digits but marked `sms = false`, because the
 * BulkSMS route is Nigerian only.
 *
 * @return array{e164: string, sms: bool, display: string}|null
 */
function se_phone_normalize(mixed $raw): ?array
{
    if (!is_string($raw) && !is_int($raw)) {
        return null;
    }

    $input = trim((string) $raw);
    if ($input === '') {
        return null;
    }

    // 1. Keep digits and a leading +.
    $hadPlus = str_starts_with($input, '+');
    $digits  = preg_replace('/\D+/', '', $input) ?? '';
    if ($digits === '') {
        return null;
    }

    // 2. Nigerian mobiles, via the existing helper.
    if (function_exists('sms_normalize_phone')) {
        $ng = sms_normalize_phone($input);
        if ($ng) {
            return ['e164' => $ng, 'sms' => true, 'display' => se_phone_display($ng)];
        }
    }

    // 3. International: only when the person actually wrote one, i.e. the
    //    input began with + or 00. A bare 10-digit string is far more likely
    //    to be a mistyped local number than a foreign one.
    $hadZeroZero = str_starts_with($digits, '00');
    if ($hadZeroZero) {
        $digits = substr($digits, 2);
    }
    if (($hadPlus || $hadZeroZero) && strlen($digits) >= 8 && strlen($digits) <= 15) {
        return ['e164' => $digits, 'sms' => false, 'display' => se_phone_display($digits)];
    }

    // 4. Anything else is not usable.
    return null;
}

/** "+234 803 123 4567" for a Nigerian number, "+44 7911 123456" otherwise. */
function se_phone_display(string $e164): string
{
    $digits = preg_replace('/\D+/', '', $e164) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 13 && str_starts_with($digits, '234')) {
        return '+234 ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3) . ' ' . substr($digits, 9);
    }

    // Generic: country-ish prefix, then groups of four from the right.
    $head = substr($digits, 0, 2);
    $tail = substr($digits, 2);
    $tail = trim(chunk_split($tail, 4, ' '));

    return '+' . $head . ' ' . $tail;
}

/** True when this number can receive an SMS through the Nigerian route. */
function se_phone_sms_capable(string $e164): bool
{
    return (bool) preg_match('/^234[789][01]\d{8}$/', $e164);
}

// --------------------------------------------------------------------------
// Member recognition (§10.3.2)
// --------------------------------------------------------------------------

/**
 * Find the ERP user behind a phone number. Nigerian numbers only.
 *
 * Two matches mean the directory holds a duplicate; we still treat the person
 * as a member (never block them) and flag `ambiguous` so the Studio can list
 * it for IDI.
 *
 * @return array{id:int, first_name:string, last_name:string, gender:?string,
 *               email:?string, ambiguous:bool}|null
 */
function se_find_member(PDO $pdo, string $e164): ?array
{
    if (!se_phone_sms_capable($e164)) {
        return null;
    }

    try {
        $local = '0' . substr($e164, 3);

        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, gender, email
             FROM users
             WHERE phone IN (:e164, :plus, :local)
             LIMIT 2"
        );
        $stmt->execute([':e164' => $e164, ':plus' => '+' . $e164, ':local' => $local]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            // Fallback: the same last 10 digits, ignoring punctuation.
            $stmt = $pdo->prepare(
                "SELECT id, first_name, last_name, gender, email FROM users
                 WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')',''), 10) = ?
                 LIMIT 2"
            );
            $stmt->execute([substr($e164, -10)]);
            $rows = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        error_log('SE identity/find_member: ' . $e->getMessage());
        return null;
    }

    if (!$rows) {
        return null;
    }

    $row = $rows[0];

    return [
        'id'         => (int) $row['id'],
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name'  => (string) ($row['last_name'] ?? ''),
        'gender'     => se_normalize_gender($row['gender'] ?? null),
        'email'      => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
        'ambiguous'  => count($rows) > 1,
    ];
}

/** 'Male' | 'Female' | null — the two values se_contacts.gender accepts. */
function se_normalize_gender(mixed $value): ?string
{
    $v = strtolower(trim((string) ($value ?? '')));

    return match (true) {
        in_array($v, ['m', 'male', 'man', 'boy'], true)      => 'Male',
        in_array($v, ['f', 'female', 'woman', 'girl'], true) => 'Female',
        default => null,
    };
}

// --------------------------------------------------------------------------
// Contacts (§10.3.3)
// --------------------------------------------------------------------------

/** One contact by phone, or null. */
function se_contact_find_by_phone(PDO $pdo, string $e164): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM se_contacts WHERE phone_e164 = ? LIMIT 1");
    $stmt->execute([$e164]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function se_contact_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM se_contacts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Get the contact for a phone number, creating it if this is the first time
 * we meet them.
 *
 * For an EXISTING contact a public flow may never overwrite the name: the
 * person typing today might be a parent registering a child from the same
 * handset, and the stored name is the one previous events used. Empty email
 * and gender may be filled in, which is pure gain.
 *
 * @param array $phone  the se_phone_normalize() result
 * @param array $fields first_name, last_name, email, gender
 */
function se_contact_get_or_create(PDO $pdo, array $phone, array $fields): array
{
    $first  = se_name_case(se_clean_name($fields['first_name'] ?? ''));
    $last   = se_name_case(se_clean_name($fields['last_name'] ?? ''));
    $email  = se_contact_clean_email($fields['email'] ?? null);
    $gender = se_normalize_gender($fields['gender'] ?? null);

    // LAST_INSERT_ID(id) makes the duplicate branch return the existing row's
    // id, so there is no check-then-insert race on phone_e164.
    $stmt = $pdo->prepare(
        "INSERT INTO se_contacts (phone_e164, phone_display, sms_capable, first_name, last_name, email, gender)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            phone_display = VALUES(phone_display),
            sms_capable   = VALUES(sms_capable),
            email         = COALESCE(NULLIF(se_contacts.email, ''), VALUES(email)),
            gender        = COALESCE(se_contacts.gender, VALUES(gender))"
    );
    $stmt->execute([
        $phone['e164'],
        $phone['display'],
        $phone['sms'] ? 1 : 0,
        $first !== '' ? $first : 'Guest',
        $last,
        $email,
        $gender,
    ]);

    $contact = se_contact_find($pdo, (int) $pdo->lastInsertId());
    if (!$contact) {
        throw new RuntimeException('se_contact_get_or_create: the contact vanished after the insert');
    }

    return se_contact_refresh_member($pdo, $contact);
}

/** Store the result of a directory lookup on a Special Events contact. */
function se_contact_cache_member(PDO $pdo, array $contact, ?array $member): array
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE se_contacts
                SET member_user_id = ?, member_ambiguous = ?, member_checked_at = NOW()
              WHERE id = ?"
        );
        $stmt->execute([
            $member['id'] ?? null,
            !empty($member['ambiguous']) ? 1 : 0,
            (int) $contact['id'],
        ]);
    } catch (Throwable $e) {
        error_log('SE identity/cache_member: ' . $e->getMessage());
        return $contact;
    }

    $contact['member_user_id']    = $member['id'] ?? null;
    $contact['member_ambiguous']  = !empty($member['ambiguous']) ? 1 : 0;
    $contact['member_checked_at'] = se_sql_datetime(se_now());

    return $contact;
}

/**
 * Keep `member_user_id` fresh. The lookup is cached on the contact and only
 * re-run when it is older than 24 hours (§10.3.2), so background operations
 * do not run the fallback directory query repeatedly. The interactive phone
 * lookup deliberately checks the directory immediately instead: somebody
 * may have joined the congregation since a previous negative result.
 */
function se_contact_refresh_member(PDO $pdo, array $contact): array
{
    $checkedAt = se_parse_datetime($contact['member_checked_at'] ?? null);
    if ($checkedAt !== null && $checkedAt > se_now()->modify('-24 hours')) {
        return $contact;
    }

    return se_contact_cache_member(
        $pdo,
        $contact,
        se_find_member($pdo, (string) $contact['phone_e164'])
    );
}

/** A trimmed, valid email, or null. */
function se_contact_clean_email(mixed $value): ?string
{
    $email = se_line($value, 190);
    if ($email === '') {
        return null;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
}

/** Record consent on the contact. Consent only ever moves up from the person. */
function se_contact_set_consent(PDO $pdo, int $contactId, bool $granted, string $consentText): void
{
    if (!$granted) {
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE se_contacts
            SET consent_followup = 1,
                consent_followup_at = NOW(),
                consent_text_hash = ?,
                opted_out_at = NULL
          WHERE id = ?"
    );
    $stmt->execute([hash('sha256', $consentText), $contactId]);
}

// --------------------------------------------------------------------------
// Devices (§10.3.5)
// --------------------------------------------------------------------------

/** The cookie name that binds one browser to one event. */
function se_device_cookie_name(string $publicId): string
{
    return 'se_dev_' . preg_replace('/[^A-Za-z0-9]/', '', $publicId);
}

/** The raw cookie value this request arrived with, if any. */
function se_device_cookie_value(string $publicId): ?string
{
    $raw = $_COOKIE[se_device_cookie_name($publicId)] ?? null;
    if (!is_string($raw) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $raw)) {
        return null;
    }

    return $raw;
}

/** Issue (or re-issue) the device cookie. Returns the value. */
function se_device_cookie_issue(array $event, array $days, ?string $existing = null): string
{
    $value = $existing ?? se_random_token(43);

    if (!headers_sent()) {
        setcookie(se_device_cookie_name((string) $event['public_id']), $value, [
            'expires'  => se_device_expiry($event, $days)->getTimestamp(),
            'path'     => '/',
            'secure'   => se_site_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[se_device_cookie_name((string) $event['public_id'])] = $value;

    return $value;
}

/** Is this request on HTTPS? The cookie's Secure flag depends on it. */
function se_site_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Device cookies and manage tokens die 30 days after the last day ends. */
function se_device_expiry(array $event, array $days): DateTimeImmutable
{
    $last = $days ? ($days[count($days) - 1]['ends_at'] ?? null) : null;
    $end  = se_parse_datetime($last) ?? se_parse_datetime($event['ends_at'] ?? null) ?? se_now();

    return $end->modify('+30 days');
}

/** Alias kept for readability where tokens, not cookies, are meant (§10.3.6). */
function se_token_expiry(array $event, array $days): DateTimeImmutable
{
    return se_device_expiry($event, $days);
}

/** The se_devices row for this browser, or null. */
function se_device_load(PDO $pdo, array $event, ?string $cookieValue): ?array
{
    if ($cookieValue === null || !se_has_hash_pepper()) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM se_devices WHERE event_id = ? AND token_hash = ? AND revoked_at IS NULL LIMIT 1"
    );
    $stmt->execute([(int) $event['id'], se_hmac('device', $cookieValue)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * The device row for this browser, created on the first state-changing
 * action. Returns null when the pepper is missing (§19.9): the module refuses
 * to mint identifiers it cannot verify later.
 */
function se_device_ensure(PDO $pdo, array $event, array $days, ?string &$cookieValue): ?array
{
    if (!se_has_hash_pepper()) {
        return null;
    }

    $cookieValue ??= se_device_cookie_value((string) $event['public_id']);
    if ($cookieValue === null) {
        $cookieValue = se_device_cookie_issue($event, $days);
    }

    $existing = se_device_load($pdo, $event, $cookieValue);
    if ($existing) {
        return $existing;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO se_devices (event_id, token_hash, mode, ua_hash, last_seen_at)
         VALUES (?, ?, 'full', ?, NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()"
    );
    $stmt->execute([(int) $event['id'], se_hmac('device', $cookieValue), se_ua_hash()]);

    return se_device_load($pdo, $event, $cookieValue);
}

/**
 * Bind a device to a registration, unless it already has an identity.
 *
 * One identity per device (§10.3.5): a mother registering her son from her own
 * phone keeps her own ticket, and the son's manage link is shown on screen
 * instead. Returns true when the binding happened.
 */
function se_device_bind_if_unbound(PDO $pdo, ?array $device, int $registrationId, string $mode = 'full'): bool
{
    if (!$device || $device['registration_id'] !== null) {
        return false;
    }

    $stmt = $pdo->prepare(
        "UPDATE se_devices SET registration_id = ?, mode = ?, last_seen_at = NOW()
          WHERE id = ? AND registration_id IS NULL"
    );
    $stmt->execute([$registrationId, $mode, (int) $device['id']]);

    return $stmt->rowCount() > 0;
}

/** Re-point a device at another registration (manage link, transfer code). */
function se_device_bind_force(PDO $pdo, ?array $device, int $registrationId, string $mode = 'full'): void
{
    if (!$device) {
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE se_devices SET registration_id = ?, mode = ?, last_seen_at = NOW() WHERE id = ?"
    );
    $stmt->execute([$registrationId, $mode, (int) $device['id']]);
}

/** Touch last_seen_at at most once a minute (§10.3.5). */
function se_device_touch(PDO $pdo, ?array $device): void
{
    if (!$device) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE se_devices SET last_seen_at = NOW()
              WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 1 MINUTE)"
        );
        $stmt->execute([(int) $device['id']]);
    } catch (Throwable $e) {
        error_log('SE identity/device_touch: ' . $e->getMessage());
    }
}

/** True when this device holds the given registration. */
function se_device_owns(?array $device, ?array $registration): bool
{
    return $device !== null
        && $registration !== null
        && $device['registration_id'] !== null
        && (int) $device['registration_id'] === (int) $registration['id'];
}

// --------------------------------------------------------------------------
// Tokens (§10.3.6)
// --------------------------------------------------------------------------

/**
 * Issue a token and return the plaintext ONCE. Only the HMAC is stored, so a
 * database leak cannot be replayed as a manage link.
 *
 * @return array{0: string, 1: int} [token, row id]
 */
function se_token_issue(PDO $pdo, int $eventId, int $registrationId, string $purpose, DateTimeImmutable $expires): array
{
    $token = $purpose === 'transfer'
        ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
        : se_random_token(SE_MANAGE_TOKEN_LENGTH);

    $stmt = $pdo->prepare(
        "INSERT INTO se_access_tokens (event_id, registration_id, purpose, token_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $eventId,
        $registrationId,
        $purpose,
        se_hmac('token/' . $purpose, $token),
        se_sql_datetime($expires),
    ]);

    return [$token, (int) $pdo->lastInsertId()];
}

/**
 * Resolve a token to its live row. Expired, revoked and used tokens return
 * null, and so does a wrong purpose — a transfer code can never open a
 * manage page.
 */
function se_token_lookup(PDO $pdo, string $token, string $purpose, ?int $eventId = null): ?array
{
    if ($token === '' || !se_has_hash_pepper()) {
        return null;
    }

    $sql = "SELECT * FROM se_access_tokens
             WHERE token_hash = ? AND purpose = ? AND revoked_at IS NULL AND expires_at > NOW()";
    $params = [se_hmac('token/' . $purpose, $token), $purpose];

    if ($eventId !== null) {
        $sql .= ' AND event_id = ?';
        $params[] = $eventId;
    }
    if ($purpose === 'transfer') {
        $sql .= ' AND used_at IS NULL';
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    try {
        $pdo->prepare("UPDATE se_access_tokens SET last_used_at = NOW() WHERE id = ?")
            ->execute([(int) $row['id']]);
    } catch (Throwable $e) {
        error_log('SE identity/token_lookup: ' . $e->getMessage());
    }

    return $row;
}

/** Revoke every token of one registration (Studio -> "Reset links"). */
function se_tokens_revoke(PDO $pdo, int $registrationId, ?string $purpose = null): int
{
    $sql    = "UPDATE se_access_tokens SET revoked_at = NOW() WHERE registration_id = ? AND revoked_at IS NULL";
    $params = [$registrationId];
    if ($purpose !== null) {
        $sql .= ' AND purpose = ?';
        $params[] = $purpose;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * A fresh manage link for one registration. Every send issues its own token
 * (§16.3), so revoking one message never breaks another.
 */
function se_manage_url(PDO $pdo, array $event, array $days, int $registrationId): string
{
    [$token] = se_token_issue($pdo, (int) $event['id'], $registrationId, 'manage', se_token_expiry($event, $days));

    return se_event_url((string) $event['slug'], 'me/' . $token);
}
