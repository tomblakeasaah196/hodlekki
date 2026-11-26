<?php
// /includes/passkeys.php
//
// Passkeys (owner, 26 Nov 2026): sign in with the phone's own fingerprint, face
// or screen lock, through WebAuthn. Owner's choices:
//   - offered right after a password sign-in on a device that has none (one tap);
//   - on the sign-in page of a device that has one, a big fingerprint that starts
//     by itself where the browser allows it (iPhone Safari needs one tap);
//   - for everyone, never required; "Forgot password" stays email-only.
//
// What is checked (W3C WebAuthn Level 2, sections 7.1 and 7.2):
//   registration   the challenge we issued (once, 10 minutes), type
//                  "webauthn.create", our origin, the RP id hash, user present
//                  AND verified (fingerprint/face/PIN), ES256 or RS256, the
//                  credential id inside the authenticator data;
//   sign-in        the same, type "webauthn.get", a known credential whose user
//                  handle matches, the signature over authData || SHA-256(clientData),
//                  and a signature counter that never goes backwards.
// The public key comes from the browser's getPublicKey() (DER SubjectPublicKeyInfo,
// every passkey-capable browser has it), so there is no CBOR to parse. We ask for
// no attestation: a passkey is trusted because the signed-in person made it.
//
// Table: auth_passkeys (db/migrations/20261224090000_auth_passkeys.sql).
// Endpoints: api/passkey_api.php. Browser side: assets/ui/js/passkey.js.
// Tests: tests/passkeys_test.php (a software authenticator built with openssl).

const PASSKEY_CHALLENGE_TTL = 600;          // seconds a challenge stays good
const PASSKEY_CHALLENGES_KEPT = 5;          // per kind, per session (open tabs)
const PASSKEY_ALGORITHMS = [-7, -257];      // ES256, RS256
const PASSKEY_MAX_PER_USER = 20;
const PASSKEY_TIMEOUT_MS = 60000;

/** base64url without padding, both ways. Decoding returns null on junk. */
function passkey_b64u_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function passkey_b64u_decode(string $text): ?string
{
    if ($text === '' || preg_match('/[^A-Za-z0-9_\-]/', $text)) {
        return null;
    }
    $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $raw === false ? null : $raw;
}

function passkeys_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM auth_passkeys LIMIT 0')->closeCursor();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Relying party: which site the passkeys belong to
// ---------------------------------------------------------------------------

/**
 * The RP id: the site's own host (hodlekki.org), so a passkey made on any
 * subdomain works on all of them. A local copy (127.0.0.1, localhost) uses
 * the host it is served on; browsers only allow "localhost" there.
 */
function passkey_rp_id(?string $requestHost = null): string
{
    $site = strtolower((string) (function_exists('site_host') ? site_host() : ''));
    $host = strtolower((string) preg_replace('/:\d+$/', '', $requestHost ?? (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($site !== '' && ($host === '' || $host === $site || str_ends_with($host, '.' . $site))) {
        return $site;
    }
    return $host !== '' ? $host : $site;
}

/** Is this clientData origin ours? https on the RP id or a subdomain; http only for localhost. */
function passkey_origin_ok(string $origin, string $rpId): bool
{
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['path']) && $parts['path'] !== '') {
        return false;
    }
    $host = strtolower($parts['host']);
    if ($host !== $rpId && !str_ends_with($host, '.' . $rpId)) {
        return false;
    }
    if ($parts['scheme'] === 'https') {
        return true;
    }
    return $parts['scheme'] === 'http' && $host === 'localhost';
}

// ---------------------------------------------------------------------------
// Challenges: random, one use, kept in the session
// ---------------------------------------------------------------------------

function passkey_challenge_issue(string $kind, ?int $userId = null): string
{
    $challenge = passkey_b64u_encode(random_bytes(32));
    $list = (array) ($_SESSION['passkey_challenges'][$kind] ?? []);
    $list[$challenge] = ['at' => time(), 'user' => $userId];
    // Keep the newest few: several open tabs may each hold one.
    uasort($list, static fn ($a, $b) => $b['at'] <=> $a['at']);
    $_SESSION['passkey_challenges'][$kind] = array_slice($list, 0, PASSKEY_CHALLENGES_KEPT, true);
    return $challenge;
}

/** Use a challenge up. True only for one we issued, for this kind (and user), still fresh. */
function passkey_challenge_take(string $kind, string $challenge, ?int $userId = null): bool
{
    $entry = $_SESSION['passkey_challenges'][$kind][$challenge] ?? null;
    if ($entry === null) {
        return false;
    }
    unset($_SESSION['passkey_challenges'][$kind][$challenge]);
    if (time() - (int) $entry['at'] > PASSKEY_CHALLENGE_TTL) {
        return false;
    }
    return $userId === null || (int) $entry['user'] === $userId;
}

// ---------------------------------------------------------------------------
// The pieces every ceremony checks
// ---------------------------------------------------------------------------

/**
 * Parse authenticator data (WebAuthn §6.1): rpIdHash(32) flags(1) signCount(4)
 * [attested credential data: aaguid(16) idLength(2) credentialId ...].
 * @return array{rp_id_hash:string, up:bool, uv:bool, be:bool, bs:bool, at:bool, sign_count:int, credential_id:?string}|null
 */
function passkey_parse_auth_data(string $authData): ?array
{
    if (strlen($authData) < 37) {
        return null;
    }
    $flags = ord($authData[32]);
    $out = [
        'rp_id_hash'    => substr($authData, 0, 32),
        'up'            => (bool) ($flags & 0x01),
        'uv'            => (bool) ($flags & 0x04),
        'be'            => (bool) ($flags & 0x08),
        'bs'            => (bool) ($flags & 0x10),
        'at'            => (bool) ($flags & 0x40),
        'sign_count'    => unpack('N', substr($authData, 33, 4))[1],
        'credential_id' => null,
    ];
    if ($out['at']) {
        if (strlen($authData) < 55) {
            return null;
        }
        $len = unpack('n', substr($authData, 53, 2))[1];
        if ($len < 1 || strlen($authData) < 55 + $len) {
            return null;
        }
        $out['credential_id'] = substr($authData, 55, $len);
    }
    return $out;
}

/**
 * Check clientDataJSON and authenticator data shared by both ceremonies.
 * @return array{ok:bool, error?:string, client?:array, auth?:array}
 */
function passkey_check_common(string $clientJson, string $authData, string $type, string $kind, ?int $userId, string $rpId): array
{
    $client = json_decode($clientJson, true);
    if (!is_array($client) || ($client['type'] ?? '') !== $type) {
        return ['ok' => false, 'error' => 'bad_client_data'];
    }
    if (!empty($client['crossOrigin'])) {
        return ['ok' => false, 'error' => 'cross_origin'];
    }
    if (!passkey_origin_ok((string) ($client['origin'] ?? ''), $rpId)) {
        return ['ok' => false, 'error' => 'bad_origin'];
    }
    if (!passkey_challenge_take($kind, (string) ($client['challenge'] ?? ''), $userId)) {
        return ['ok' => false, 'error' => 'bad_challenge'];
    }
    $auth = passkey_parse_auth_data($authData);
    if ($auth === null || !hash_equals(hash('sha256', $rpId, true), $auth['rp_id_hash'])) {
        return ['ok' => false, 'error' => 'bad_rp'];
    }
    // Present AND verified: the fingerprint, face or screen lock was used.
    if (!$auth['up'] || !$auth['uv']) {
        return ['ok' => false, 'error' => 'not_verified'];
    }
    return ['ok' => true, 'client' => $client, 'auth' => $auth];
}

/** DER SubjectPublicKeyInfo → PEM, checked to be the algorithm claimed. */
function passkey_public_key_pem(string $spki, int $alg): ?string
{
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return null;
    }
    $details = openssl_pkey_get_details($key);
    if ($alg === -7) {
        return ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1' ? $pem : null;
    }
    if ($alg === -257) {
        return ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? 0) >= 2048 ? $pem : null;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Registration (a signed-in person adds a passkey on this device)
// ---------------------------------------------------------------------------

/** The person's passkey user handle: the one their other passkeys carry, or a new random one. */
function passkey_user_handle(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT user_handle FROM auth_passkeys WHERE user_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$userId]);
    $handle = $stmt->fetchColumn();
    return is_string($handle) && $handle !== '' ? $handle : passkey_b64u_encode(random_bytes(32));
}

/**
 * Options for navigator.credentials.create(), binary fields as base64url
 * (assets/ui/js/passkey.js turns them into ArrayBuffers). The handle is held
 * in the session with the challenge so finish uses the same one.
 */
function passkey_register_options(PDO $pdo, array $user): array
{
    $userId = (int) $user['id'];
    $handle = passkey_user_handle($pdo, $userId);
    $_SESSION['passkey_handle'][$userId] = $handle;
    $stmt = $pdo->prepare('SELECT credential_id, transports FROM auth_passkeys WHERE user_id = ?');
    $stmt->execute([$userId]);
    $exclude = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $one = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
        if (!empty($row['transports'])) {
            $one['transports'] = explode(',', (string) $row['transports']);
        }
        $exclude[] = $one;
    }
    $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
    $login = (string) (($user['real_email'] ?? '') ?: ($user['email'] ?? '') ?: ($user['phone'] ?? '') ?: $name);
    return [
        'challenge' => passkey_challenge_issue('register', $userId),
        'rp' => ['id' => passkey_rp_id(), 'name' => 'HOD Lekki'],
        'user' => ['id' => $handle, 'name' => $login !== '' ? $login : 'member', 'displayName' => $name !== '' ? $name : $login],
        'pubKeyCredParams' => array_map(static fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg], PASSKEY_ALGORITHMS),
        'authenticatorSelection' => [
            'authenticatorAttachment' => 'platform',   // this phone's own fingerprint/face/PIN
            'residentKey' => 'required',               // so sign-in needs no email
            'requireResidentKey' => true,
            'userVerification' => 'required',
        ],
        'attestation' => 'none',
        'excludeCredentials' => $exclude,
        'timeout' => PASSKEY_TIMEOUT_MS,
    ];
}

/**
 * Check what the browser made and keep it. $in: id (base64url), clientDataJSON,
 * authenticatorData, publicKey (DER SPKI), publicKeyAlgorithm, transports[].
 * @return array{ok:bool, code?:string, id?:int, label?:string}
 */
function passkey_register_finish(PDO $pdo, array $user, array $in, string $userAgent = '', string $ip = ''): array
{
    $userId = (int) $user['id'];
    $credentialId = (string) ($in['id'] ?? '');
    $rawId = passkey_b64u_decode($credentialId);
    $clientJson = passkey_b64u_decode((string) ($in['clientDataJSON'] ?? ''));
    $authData = passkey_b64u_decode((string) ($in['authenticatorData'] ?? ''));
    $spki = passkey_b64u_decode((string) ($in['publicKey'] ?? ''));
    $alg = (int) ($in['publicKeyAlgorithm'] ?? 0);
    if ($rawId === null || $clientJson === null || $authData === null || $spki === null || strlen($credentialId) > 255) {
        return ['ok' => false, 'code' => 'BAD_REQUEST'];
    }
    if (!in_array($alg, PASSKEY_ALGORITHMS, true)) {
        return ['ok' => false, 'code' => 'UNSUPPORTED'];
    }
    $check = passkey_check_common($clientJson, $authData, 'webauthn.create', 'register', $userId, passkey_rp_id());
    if (!$check['ok']) {
        return ['ok' => false, 'code' => 'NOT_ACCEPTED', 'why' => $check['error']];
    }
    if (!$check['auth']['at'] || !hash_equals((string) $check['auth']['credential_id'], $rawId)) {
        return ['ok' => false, 'code' => 'NOT_ACCEPTED', 'why' => 'credential_mismatch'];
    }
    $pem = passkey_public_key_pem($spki, $alg);
    if ($pem === null) {
        return ['ok' => false, 'code' => 'UNSUPPORTED'];
    }
    $handle = (string) ($_SESSION['passkey_handle'][$userId] ?? '');
    if ($handle === '') {
        $handle = passkey_user_handle($pdo, $userId);
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM auth_passkeys WHERE user_id = ?');
    $count->execute([$userId]);
    if ((int) $count->fetchColumn() >= PASSKEY_MAX_PER_USER) {
        return ['ok' => false, 'code' => 'TOO_MANY'];
    }
    $transports = array_values(array_filter((array) ($in['transports'] ?? []), static fn ($t) => is_string($t) && preg_match('/^[a-z\-]{2,20}$/', $t)));
    $label = passkey_label($userAgent);
    try {
        $pdo->prepare('INSERT INTO auth_passkeys (user_id, credential_id, user_handle, public_key, algorithm, sign_count, transports, backed_up, label, created_at, created_ip)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $credentialId, $handle, $pem, $alg, $check['auth']['sign_count'],
                       $transports !== [] ? substr(implode(',', $transports), 0, 100) : null, $check['auth']['be'] ? 1 : 0,
                       $label, date('Y-m-d H:i:s'), $ip !== '' ? substr($ip, 0, 45) : null]);
    } catch (PDOException $e) {
        // The UNIQUE key: this exact passkey is already on file.
        return ['ok' => false, 'code' => 'ALREADY'];
    }
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'label' => $label];
}

/** "Chrome on Android", "Safari on iPhone"… from the device that made it. */
function passkey_label(string $userAgent): string
{
    $device = function_exists('security_describe_device') ? security_describe_device($userAgent) : '';
    $device = trim((string) $device) !== '' ? (string) $device : 'This device';
    return substr($device, 0, 100);
}

// ---------------------------------------------------------------------------
// Sign-in (nobody is signed in yet)
// ---------------------------------------------------------------------------

/** Options for navigator.credentials.get(): no list of credentials, so the phone offers its own. */
function passkey_login_options(): array
{
    return [
        'challenge' => passkey_challenge_issue('login'),
        'rpId' => passkey_rp_id(),
        'userVerification' => 'required',
        'timeout' => PASSKEY_TIMEOUT_MS,
    ];
}

/**
 * Check a sign-in. $in: id, clientDataJSON, authenticatorData, signature, userHandle.
 * @return array{ok:bool, code?:string, user_id?:int, passkey_id?:int}
 *   code: BAD_REQUEST | UNKNOWN (no such passkey: deleted, or never saved) | NOT_ACCEPTED
 */
function passkey_login_finish(PDO $pdo, array $in): array
{
    $credentialId = (string) ($in['id'] ?? '');
    $clientJson = passkey_b64u_decode((string) ($in['clientDataJSON'] ?? ''));
    $authData = passkey_b64u_decode((string) ($in['authenticatorData'] ?? ''));
    $signature = passkey_b64u_decode((string) ($in['signature'] ?? ''));
    if ($credentialId === '' || strlen($credentialId) > 255 || $clientJson === null || $authData === null || $signature === null) {
        return ['ok' => false, 'code' => 'BAD_REQUEST'];
    }
    $stmt = $pdo->prepare('SELECT * FROM auth_passkeys WHERE credential_id = ?');
    $stmt->execute([$credentialId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Use the challenge up anyway, so it cannot be tried again.
        $client = json_decode($clientJson, true);
        passkey_challenge_take('login', (string) ($client['challenge'] ?? ''));
        return ['ok' => false, 'code' => 'UNKNOWN'];
    }
    $handle = (string) ($in['userHandle'] ?? '');
    if ($handle !== '' && !hash_equals((string) $row['user_handle'], $handle)) {
        return ['ok' => false, 'code' => 'NOT_ACCEPTED', 'why' => 'handle_mismatch'];
    }
    $check = passkey_check_common($clientJson, $authData, 'webauthn.get', 'login', null, passkey_rp_id());
    if (!$check['ok']) {
        return ['ok' => false, 'code' => 'NOT_ACCEPTED', 'why' => $check['error']];
    }
    $signed = $authData . hash('sha256', $clientJson, true);
    if (openssl_verify($signed, $signature, (string) $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'code' => 'NOT_ACCEPTED', 'why' => 'bad_signature'];
    }
    // A counter that does not move forward means a copied authenticator
    // (synced passkeys always send 0, which is fine).
    $old = (int) $row['sign_count'];
    $new = (int) $check['auth']['sign_count'];
    if ($old > 0 && $new <= $old) {
        return ['ok' => false, 'code' => 'NOT_ACCEPTED', 'why' => 'counter'];
    }
    $pdo->prepare('UPDATE auth_passkeys SET sign_count = ?, backed_up = ?, last_used_at = ? WHERE id = ?')
        ->execute([max($old, $new), $check['auth']['bs'] ? 1 : (int) $row['backed_up'], date('Y-m-d H:i:s'), (int) $row['id']]);
    return ['ok' => true, 'user_id' => (int) $row['user_id'], 'passkey_id' => (int) $row['id']];
}

// ---------------------------------------------------------------------------
// The one-tap offer after a password sign-in (includes/header.php)
// ---------------------------------------------------------------------------

/**
 * The card, hidden; assets/ui/js/passkey.js shows it only when this device can
 * make a passkey and has none, and the person has not said "Not now" here in
 * 30 days. The options travel with the page, so the tap opens the phone's own
 * prompt at once (no round trip: under 5 seconds from tap to done).
 */
function passkey_offer_html(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId];
    return '<div class="ui-pk-offer" data-passkey-offer hidden role="region" aria-labelledby="pk-offer-title">'
        . '<span class="ui-pk-offer__icon">' . ui_icon('fingerprint') . '</span>'
        . '<div class="min-w-0 flex-1">'
        . '<p class="ui-pk-offer__title" id="pk-offer-title">Faster sign-in</p>'
        . '<p class="ui-pk-offer__text">Use your fingerprint or face next time.</p>'
        . '<div class="ui-pk-offer__actions">'
        . ui_button('Turn on', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'passkey.offer-on']])
        . ui_button('Not now', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'passkey.offer-skip']])
        . '</div></div></div>'
        . ui_json_script('passkey-offer-options', passkey_register_options($pdo, $user));
}

// ---------------------------------------------------------------------------
// The person's own list (Profile)
// ---------------------------------------------------------------------------

/** @return list<array{id:int, label:string, created_at:string, last_used_at:?string, synced:bool}> */
function passkey_list(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, label, created_at, last_used_at, backed_up FROM auth_passkeys WHERE user_id = ? ORDER BY id DESC');
    $stmt->execute([$userId]);
    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'label' => (string) $r['label'],
        'created_at' => (string) $r['created_at'],
        'last_used_at' => $r['last_used_at'] !== null ? (string) $r['last_used_at'] : null,
        'synced' => (int) $r['backed_up'] === 1,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Remove every passkey of one person: an administrator's password reset
 * (api/security_api.php) is for a lost or taken account, and a passkey on a
 * lost phone would otherwise still open it. Returns how many went.
 */
function passkey_delete_all(PDO $pdo, int $userId): int
{
    if (!passkeys_ready($pdo)) {
        return 0;
    }
    $stmt = $pdo->prepare('DELETE FROM auth_passkeys WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/** Remove one of the person's own passkeys. True when one was removed. */
function passkey_delete(PDO $pdo, int $userId, int $passkeyId): bool
{
    $stmt = $pdo->prepare('DELETE FROM auth_passkeys WHERE id = ? AND user_id = ?');
    $stmt->execute([$passkeyId, $userId]);
    return $stmt->rowCount() === 1;
}
