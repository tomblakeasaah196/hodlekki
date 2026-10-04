<?php
// /includes/sms_functions.php
/**
 * SMS STUDIO — Shared helper functions
 * Used by api/sms_api.php, api/sms_webhook.php and cron/sms_queue_worker.php.
 *
 * Message lifecycle (sms_log.status):
 *   queued    row written just before the BulkSMS call (so a crash mid-call
 *             can never leave a billed message with no record)
 *   sent      BulkSMS accepted it and returned a message ID
 *   pending   the carrier reported an interim state (ACCEPTD, ENROUTE, ...)
 *   delivered the carrier confirmed delivery to the handset
 *   failed    refused by BulkSMS (nothing billed) or undelivered per the carrier
 *   blocked   stopped by our own spam guard / suppression list, never sent (₦0)
 *   unknown   outcome cannot be known (lost response, or no delivery report
 *             within SMS_DLR_GIVE_UP_HOURS)
 * Every movement is written to sms_status_events with its exact time.
 */
require_once __DIR__ . '/sms_vault_key.php';  // defines SMS_VAULT_KEY + sms_vault_ready()
require_once __DIR__ . '/sms_status.php';     // sms_map_dlr_status(), sms_explain_code()

const SMS_DEFAULT_BASE      = 'https://www.bulksmsnigeria.com/api/v2';
const SMS_ALLOWED_ROLES     = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
const SMS_GATEWAYS          = ['direct-refund', 'direct-corporate', 'otp', 'dual-backup'];
const SMS_BURST_PER_HOUR    = 3;      // messages to one handset per rolling hour
const SMS_SUPPRESS_AFTER    = 3;      // consecutive carrier failures before auto-suppression
const SMS_DLR_GIVE_UP_HOURS = 72;     // no final delivery report after this -> 'unknown'
const SMS_AUDIENCE_CAP      = 5000;   // contacts returned to the composer per query
const SMS_MAX_CAMPAIGN      = 10000;  // recipients accepted in one campaign
const SMS_WORKER_CLAIM      = 10;     // queue rows claimed per loop
const SMS_MIN_SEND_GAP      = 1.0;    // seconds between sends (BulkSMS allows 120 req/min in total)
const SMS_POLLS_PER_RUN     = 30;     // delivery-report look-ups per worker run
const SMS_WORKER_LOCK       = 'hod_sms_queue_worker';

/* ============================================================================
 * ACCESS
 * ========================================================================== */
function sms_user_can_send() {
    if (empty($_SESSION['roles']) || !is_array($_SESSION['roles'])) return false;
    foreach ($_SESSION['roles'] as $r) {
        if (in_array($r['role_name'] ?? '', SMS_ALLOWED_ROLES, true)) return true;
    }
    return false;
}
function sms_user_is_super_admin() {
    if (empty($_SESSION['roles']) || !is_array($_SESSION['roles'])) return false;
    foreach ($_SESSION['roles'] as $r) {
        if (($r['role_name'] ?? '') === 'Super_Admin') return true;
    }
    return false;
}

/* ============================================================================
 * SCHEMA PROBES (the hardening migrations may not have run yet on a host)
 * ========================================================================== */
function sms_table_exists(PDO $pdo, $table) {
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        $cache[$table] = (int)$st->fetchColumn() > 0;
    }
    return $cache[$table];
}
function sms_column_exists(PDO $pdo, $table, $column) {
    static $cache = [];
    $k = $table . '.' . $column;
    if (!array_key_exists($k, $cache)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $column]);
        $cache[$k] = (int)$st->fetchColumn() > 0;
    }
    return $cache[$k];
}
/** True once 20261004090200_sms_columns_and_indexes.sql has been applied. */
function sms_schema_ready(PDO $pdo) {
    return sms_column_exists($pdo, 'sms_queue', 'log_id') && sms_table_exists($pdo, 'sms_status_events');
}

/* ============================================================================
 * SMALL STRING HELPERS
 * ========================================================================== */
function sms_cut($s, $len) {
    $s = (string)$s;
    return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
}
/** Trim a name and collapse runs of whitespace / line breaks to one space. */
function sms_clean_name($s) {
    return trim(preg_replace('/\s+/u', ' ', (string)$s) ?? (string)$s);
}

/* ============================================================================
 * ENCRYPTION HELPERS (AES-256-GCM)
 * ========================================================================== */
function sms_key_bytes() {
    if (!sms_vault_ready()) {
        throw new RuntimeException('SMS_VAULT_KEY is missing or is not 64 hex characters. Set it in .env.');
    }
    return hex2bin(SMS_VAULT_KEY); // 32 bytes
}
function sms_encrypt($plain) {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt((string)$plain, 'aes-256-gcm', sms_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Could not encrypt the SMS setting.');
    return [
        'enc' => base64_encode($cipher),
        'iv'  => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}
/** Returns the plaintext, '' for an empty value, or null when it cannot be decrypted. */
function sms_decrypt($enc, $iv, $tag) {
    if ($enc === null || $enc === '') return '';
    if (!sms_vault_ready()) return null;
    $raw = openssl_decrypt(base64_decode($enc), 'aes-256-gcm', sms_key_bytes(), OPENSSL_RAW_DATA, base64_decode((string)$iv), base64_decode((string)$tag));
    return $raw === false ? null : $raw;
}

/* ============================================================================
 * SETTINGS LOAD / SAVE (encrypted rows)
 * ========================================================================== */
const SMS_KEYS = ['base_url', 'api_token', 'sender_id', 'gateway', 'webhook_url'];

/**
 * $failed receives the keys that exist but could not be decrypted (the vault
 * key was rotated or is missing) so callers can say so instead of reporting
 * a misleading "not configured".
 */
function sms_load_settings(PDO $pdo, &$failed = null) {
    $out = [];
    $failed = [];
    $rows = $pdo->query("SELECT skey, enc_value, iv, tag FROM sms_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $v = sms_decrypt($r['enc_value'], $r['iv'], $r['tag']);
        if ($v === null) { $failed[] = $r['skey']; $v = ''; }
        $out[$r['skey']] = $v;
    }
    if ($failed) error_log('SMS: cannot decrypt sms_settings keys (' . implode(', ', $failed) . ') — SMS_VAULT_KEY changed or missing?');
    foreach (SMS_KEYS as $k) if (!isset($out[$k])) $out[$k] = '';
    return $out;
}
function sms_save_setting(PDO $pdo, $key, $value, $userId) {
    $enc = sms_encrypt($value);
    $stmt = $pdo->prepare("
        INSERT INTO sms_settings (skey, enc_value, iv, tag, updated_by)
        VALUES (:k, :e, :i, :t, :u)
        ON DUPLICATE KEY UPDATE enc_value = VALUES(enc_value), iv = VALUES(iv), tag = VALUES(tag), updated_by = VALUES(updated_by)
    ");
    $stmt->execute(['k'=>$key,'e'=>$enc['enc'],'i'=>$enc['iv'],'t'=>$enc['tag'],'u'=>$userId]);
}
function sms_base_url(array $settings) {
    return rtrim(($settings['base_url'] ?? '') ?: SMS_DEFAULT_BASE, '/');
}
/** SMS_WEBHOOK_SECRET from .env (db.php fills $_ENV; getenv() alone never saw it). */
function sms_webhook_secret() {
    $s = $_ENV['SMS_WEBHOOK_SECRET'] ?? getenv('SMS_WEBHOOK_SECRET');
    return trim((string)($s === false ? '' : $s));
}

/* ============================================================================
 * AUDIENCE QUERIES
 * Each returns ['data' => [...], 'dropped' => invalid numbers skipped,
 *               'duplicates' => extra rows sharing a number, 'truncated' => bool]
 * ========================================================================== */
function sms_audience_users(PDO $pdo, $statuses, $search, $cap = SMS_AUDIENCE_CAP) {
    $sql = "SELECT id, first_name, last_name, phone, email, spiritual_status
            FROM users
            WHERE phone IS NOT NULL AND TRIM(phone) <> ''
              AND COALESCE(attendance_status,'') <> 'Relocated'";
    // Numbers the Contact Extractor / audits have marked invalid never get billed.
    if (sms_column_exists($pdo, 'users', 'phone_status')) {
        $sql .= " AND COALESCE(phone_status,'') <> 'invalid'";
    }
    $params = [];
    if (!empty($statuses) && is_array($statuses)) {
        $ph = [];
        foreach (array_values($statuses) as $i => $s) { $ph[] = ':st'.$i; $params['st'.$i] = $s; }
        $sql .= " AND spiritual_status IN (" . implode(',', $ph) . ")";
    }
    if ($search !== '' && $search !== null) {
        $sql .= " AND (CONCAT(first_name,' ',last_name) LIKE :q1 OR phone LIKE :q2 OR email LIKE :q3)";
        $params['q1'] = "%{$search}%";
        $params['q2'] = "%{$search}%";
        $params['q3'] = "%{$search}%";
    }
    $sql .= " ORDER BY first_name ASC, last_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = []; $seen = []; $dropped = 0; $dupes = 0; $truncated = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $normPhone = sms_normalize_phone($u['phone']);
        if ($normPhone === null) { $dropped++; continue; }
        if (isset($seen[$normPhone])) { $dupes++; continue; }
        if (count($out) >= $cap) { $truncated = true; break; }
        $seen[$normPhone] = true;
        $first = sms_clean_name($u['first_name']);
        $last  = sms_clean_name($u['last_name']);
        $out[] = [
            'source' => 'user', 'source_id' => (int)$u['id'],
            'name' => trim($first . ' ' . $last),
            'phone' => $normPhone,
            'first_name' => $first, 'last_name' => $last,
            'email' => $u['email'] ?? '',
            'event_title' => '',
            'status_label' => $u['spiritual_status'] ?? '',
        ];
    }
    return ['data'=>$out, 'dropped'=>$dropped, 'duplicates'=>$dupes, 'truncated'=>$truncated];
}

/**
 * Check-ins audience: everyone who checked into an event (all days), deduped by
 * phone so Day-1+Day-2 attendees get ONE message. Uses their LATEST check-in's
 * blessing_ref. First name = the part before the first space in full_name.
 */
function sms_audience_checkins(PDO $pdo, $eventId, $search, $cap = SMS_AUDIENCE_CAP) {
    $sql = "SELECT c.id, c.full_name, c.phone, c.event_id,
                   c.blessing_ref, e.title AS event_title
            FROM checkins c
            LEFT JOIN events e ON e.id = c.event_id
            WHERE c.phone IS NOT NULL AND TRIM(c.phone) <> ''";
    $params = [];
    if (!empty($eventId)) { $sql .= " AND c.event_id = :eid"; $params['eid'] = $eventId; }
    if ($search !== '' && $search !== null) {
        $sql .= " AND (c.full_name LIKE :q1 OR c.phone LIKE :q2)";
        $params['q1'] = "%{$search}%";
        $params['q2'] = "%{$search}%";
    }
    $sql .= " ORDER BY c.id DESC";   // most recent check-in first
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = []; $out = []; $dropped = 0; $dupes = 0; $truncated = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $normPhone = sms_normalize_phone($r['phone']);
        if ($normPhone === null) { $dropped++; continue; }
        if (isset($seen[$normPhone])) { $dupes++; continue; }
        if (count($out) >= $cap) { $truncated = true; break; }
        $seen[$normPhone] = true;

        $fullName  = sms_clean_name($r['full_name']);
        $firstName = $fullName !== '' ? explode(' ', $fullName)[0] : '';
        $lastName  = strpos($fullName, ' ') !== false ? trim(substr($fullName, strpos($fullName, ' ') + 1)) : '';

        $out[] = [
            'source' => 'checkin', 'source_id' => (int)$r['id'],
            'name' => $fullName ?: 'Guest',
            'phone' => $normPhone,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => '',
            'event_title' => $r['event_title'] ?? '',
            'blessing_ref' => $r['blessing_ref'] ?? '',
        ];
    }
    return ['data'=>$out, 'dropped'=>$dropped, 'duplicates'=>$dupes, 'truncated'=>$truncated];
}

function sms_audience_registrations(PDO $pdo, $eventId, $search, $cap = SMS_AUDIENCE_CAP) {
    $sql = "SELECT r.id AS reg_id, r.event_id, r.guest_name, r.guest_phone, r.guest_email, r.user_id, r.matched_user_id,
                   u.first_name, u.last_name, u.phone AS user_phone, u.email AS user_email,
                   e.title AS event_title
            FROM event_registrations r
            LEFT JOIN users u ON u.id = COALESCE(r.user_id, r.matched_user_id)
            LEFT JOIN events e ON e.id = r.event_id
            WHERE 1=1";
    $params = [];
    if (!empty($eventId)) { $sql .= " AND r.event_id = :eid"; $params['eid'] = $eventId; }
    if ($search !== '' && $search !== null) {
        $sql .= " AND (r.guest_name LIKE :q1 OR r.guest_phone LIKE :q2 OR r.guest_email LIKE :q3 OR CONCAT(u.first_name,' ',u.last_name) LIKE :q4)";
        $params['q1'] = "%{$search}%";
        $params['q2'] = "%{$search}%";
        $params['q3'] = "%{$search}%";
        $params['q4'] = "%{$search}%";
    }
    $sql .= " ORDER BY r.registered_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = []; // dedupe by NORMALIZED phone so 0902... and 234902... collapse to one
    $out = []; $dropped = 0; $dupes = 0; $truncated = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $phone = trim($r['guest_phone'] !== null && $r['guest_phone'] !== '' ? $r['guest_phone'] : ($r['user_phone'] ?? ''));
        $normPhone = sms_normalize_phone($phone);
        if ($normPhone === null) { $dropped++; continue; }
        if (isset($seen[$normPhone])) { $dupes++; continue; }
        if (count($out) >= $cap) { $truncated = true; break; }
        $seen[$normPhone] = true;

        $guestName = sms_clean_name($r['guest_name']);
        $firstName = sms_clean_name($r['first_name']) ?: (explode(' ', $guestName)[0] ?? '');
        $lastName  = sms_clean_name($r['last_name']);
        $name      = $r['first_name'] ? trim(sms_clean_name($r['first_name']) . ' ' . $lastName) : $guestName;
        $email     = $r['guest_email'] ?: ($r['user_email'] ?? '');

        $out[] = [
            'source' => 'registration', 'source_id' => (int)$r['reg_id'],
            'name' => $name ?: 'Guest',
            'phone' => $normPhone,
            'first_name' => $firstName, 'last_name' => $lastName,
            'email' => $email,
            'guest_name' => $guestName,
            'event_title' => $r['event_title'] ?? '',
        ];
    }
    return ['data'=>$out, 'dropped'=>$dropped, 'duplicates'=>$dupes, 'truncated'=>$truncated];
}

/* ============================================================================
 * TEXT: GSM-7 clean-up, segment counting, merge-field rendering
 * ========================================================================== */
/**
 * Replace typographic look-alikes (curly quotes, long dashes, ellipsis,
 * non-breaking / zero-width spaces) with their plain GSM-7 equivalents. One
 * pasted ’ used to flip a whole message to Unicode: 70 characters per SMS
 * instead of 160, so a 1-page message billed as 3 pages. The composer applies
 * the same table, so the preview and the counter match what is sent.
 */
function sms_gsm_clean($text) {
    static $map = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2032}" => "'", "\u{00B4}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"', "\u{2033}" => '"',
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
        "\u{2026}" => '...', "\u{2022}" => '-',
        "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{2009}" => ' ', "\u{200A}" => ' ', "\u{202F}" => ' ',
        "\u{200B}" => '', "\u{FEFF}" => '', "\u{00AD}" => '',
        "\r\n" => "\n",
    ];
    return strtr((string)$text, $map);
}

/** Encoding + billable parts (GSM-7 160/153, Unicode 70/67), as the handset network counts them. */
function sms_segments($text) {
    static $gsm = null, $ext = null;
    if ($gsm === null) {
        $gsm = array_flip(preg_split('//u', "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", -1, PREG_SPLIT_NO_EMPTY));
        $ext = array_flip(preg_split('//u', "^{}\\[~]|€", -1, PREG_SPLIT_NO_EMPTY));
    }
    $chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $len = 0; $unicode = false; $ucs2 = 0;
    foreach ($chars as $ch) {
        $ucs2 += strlen($ch) === 4 ? 2 : 1;       // astral characters (emoji) take two UCS-2 units
        if (isset($gsm[$ch])) $len += 1;
        elseif (isset($ext[$ch])) $len += 2;
        else $unicode = true;
    }
    if ($unicode) {
        return ['encoding' => 'Unicode', 'chars' => $ucs2, 'pages' => $ucs2 <= 70 ? 1 : (int)ceil($ucs2 / 67)];
    }
    return ['encoding' => 'GSM-7', 'chars' => $len, 'pages' => $len <= 160 ? 1 : (int)ceil($len / 153)];
}

function sms_render($template, array $data) {
    $firstName = sms_clean_name($data['first_name'] ?? '');
    $lastName  = sms_clean_name($data['last_name'] ?? '');

    // Resolve the guest's full name, then take only the FIRST name (up to the first space)
    $fullGuest = sms_clean_name($data['guest_name'] ?? '');
    if ($fullGuest === '' && $firstName !== '') {
        $fullGuest = trim($firstName . ' ' . $lastName);
    } elseif ($fullGuest === '') {
        $fullGuest = sms_clean_name($data['name'] ?? '');
    }
    $guestFirstName = $fullGuest !== '' ? explode(' ', $fullGuest)[0] : '';

    $map = [
        '{{first_name}}'   => $firstName,
        '{{last_name}}'    => $lastName,
        '{{full_name}}'    => trim($firstName . ' ' . $lastName),
        '{{guest_name}}'   => $guestFirstName,
        '{{name}}'         => $fullGuest !== '' ? $fullGuest : sms_clean_name($data['name'] ?? ''),
        '{{phone}}'        => trim((string)($data['phone'] ?? '')),
        '{{email}}'        => trim((string)($data['email'] ?? '')),
        '{{event_title}}'  => trim((string)($data['event_title'] ?? '')),
        '{{blessing_ref}}' => trim((string)($data['blessing_ref'] ?? '')),
        // Module-generated messages only (Special Events, guide §21.2). The
        // SMS Studio composer strips unknown recipient keys, so a hand-written
        // campaign simply renders this as an empty string.
        '{{link}}'         => trim((string)($data['link'] ?? '')),
    ];
    return trim(sms_gsm_clean(strtr((string)$template, $map)));
}

/* ============================================================================
 * BulkSMS HTTP CLIENT (v2: from / to / body)
 * ========================================================================== */
function sms_http($method, $url, $token, $payload = null, $attempt = 0, $allowRetry = true) {
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'HOD-Lekki-SMS-Studio/2');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);

    // Transient failures: retry with exponential backoff (429 rate-limit / 5xx server / timeouts).
    // NOTE: billable POSTs pass allowRetry=false — a lost response must NOT be re-sent,
    // otherwise one send becomes multiple billed messages.
    $retryable = $allowRetry && (($body === false) || in_array($code, [429, 500, 502, 503, 504], true));
    if ($retryable && $attempt < 2) {
        sleep(1 + $attempt); // 1s, then 2s
        return sms_http($method, $url, $token, $payload, $attempt + 1, $allowRetry);
    }

    if ($body === false) return ['ok'=>false, 'status'=>0, 'errno'=>$errno, 'message'=>$err ?: 'Request failed', 'data'=>null, 'raw'=>null];
    $json = json_decode($body, true);
    return ['ok'=>true, 'status'=>$code, 'errno'=>0, 'message'=>null, 'data'=>is_array($json) ? $json : null, 'raw'=>substr((string)$body, 0, 2000)];
}

/** Transport OK, HTTP 2xx, JSON body, and BulkSMS did not say status=error. */
function sms_http_ok(array $res) {
    return $res['ok'] && $res['status'] >= 200 && $res['status'] < 300 && is_array($res['data'])
        && strtolower((string)($res['data']['status'] ?? 'success')) !== 'error';
}

/** Plain-English reason for a failed BulkSMS call (network, HTTP status, BSNG code, validation errors). */
function sms_api_error(array $res) {
    if (!$res['ok']) return 'Could not reach BulkSMS (' . ($res['message'] ?: 'network error') . ').';
    $d = $res['data'];
    $msg = null; $code = null;
    if (is_array($d)) {
        $msg  = $d['message'] ?? ($d['error'] ?? null);
        $code = $d['code'] ?? ($d['error_code'] ?? null);
        if (is_array($msg)) $msg = json_encode($msg);
        if (!empty($d['errors']) && is_array($d['errors'])) {       // Laravel-style validation errors
            $first = reset($d['errors']);
            $first = is_array($first) ? reset($first) : $first;
            if ($first) $msg = trim(($msg ? $msg . ': ' : '') . $first);
        }
    }
    if ($msg === null || $msg === '') {
        $msg = 'BulkSMS answered HTTP ' . $res['status'] . ($d === null ? ' with a non-JSON reply' : '');
    }
    if ($code && ($x = sms_explain_code($code))) {
        $msg .= ' — ' . $x;
    } elseif ((int)$res['status'] === 401) {
        $msg .= ' — the API token was rejected; check it in Settings.';
    }
    return $msg;
}

/**
 * Normalize any Nigerian phone to international 234XXXXXXXXXX and validate it.
 * Accepts: 08012345678, 8012345678, +2348012345678, 2348012345678,
 *          002348012345678 and the common "+234 (0) 801 234 5678" typo.
 * Returns the normalized number, or null if it is not a valid NG mobile.
 */
function sms_normalize_phone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return null;
    if (strncmp($p, '00', 2) === 0) $p = substr($p, 2);                      // 00234... international prefix
    if (strlen($p) === 14 && strncmp($p, '2340', 4) === 0) {
        $p = '234' . substr($p, 4);                                           // +234 (0)801... -> 234801...
    } elseif (strlen($p) === 11 && $p[0] === '0') {
        $p = '234' . substr($p, 1);                                           // 08012345678 -> 2348012345678
    } elseif (strlen($p) === 10 && $p[0] !== '0') {
        $p = '234' . $p;                                                      // 8012345678  -> 2348012345678
    }
    // valid NG mobile: 234 + [7/8/9] + [0/1] + 8 digits  (e.g. 2348012345678)
    return preg_match('/^234[789][01]\d{8}$/', $p) ? $p : null;
}

/* ============================================================================
 * SEND GUARD (spam protection) + SUPPRESSION
 * ========================================================================== */
/**
 * Refuse sends that Nigerian carriers treat as spam. Returns a human-readable
 * block reason, or null to proceed.
 *
 * Only messages that actually reached (or may have reached) the carrier count.
 * Earlier this counted every sms_log row — including 'blocked' rows and sends
 * BulkSMS refused (no balance, bad sender ID) — so after topping up the wallet
 * a Resend was refused for 24 hours as a "duplicate", and each refused
 * attempt added a row that extended the block.
 */
function sms_send_guard(PDO $pdo, $phone, $body, $skipDuplicate = false) {

    // 1) Identical body to the same handset inside 24h that was delivered, is
    //    still on its way, or may have gone out (duplicate-content filter)
    if (!$skipDuplicate) {
        $st = $pdo->prepare("SELECT status, created_at FROM sms_log
                             WHERE recipient_phone = ? AND body = ?
                               AND created_at > (NOW() - INTERVAL 24 HOUR)
                               AND status IN ('queued','sent','pending','delivered','unknown')
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$phone, $body]);
        if ($prev = $st->fetch(PDO::FETCH_ASSOC)) {
            return "Blocked: this exact message already went to {$phone} at {$prev['created_at']} (status: {$prev['status']}). "
                 . "Carriers drop repeats of the same text to the same number within 24 hours. Change the wording or wait. Nothing was sent (₦0).";
        }
    }

    // 2) Burst cap — max SMS_BURST_PER_HOUR messages that reached the carrier per handset per hour
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_log
                         WHERE recipient_phone = ?
                           AND created_at > (NOW() - INTERVAL 1 HOUR)
                           AND status IN ('queued','sent','pending','delivered','unknown','failed')
                           AND NOT (status = 'failed' AND (message_id IS NULL OR message_id = ''))");
    $st->execute([$phone]);
    if ((int)$st->fetchColumn() >= SMS_BURST_PER_HOUR) {
        return "Blocked: " . SMS_BURST_PER_HOUR . " messages already went to {$phone} in the past hour. "
             . "Airtel and MTN throttle at this rate. Try again later. Nothing was sent (₦0).";
    }

    // 3) Suppression list (if the table exists)
    try {
        $st = $pdo->prepare("SELECT reason FROM sms_suppression
                             WHERE phone = ? AND released_at IS NULL LIMIT 1");
        $st->execute([$phone]);
        if (($reason = $st->fetchColumn()) !== false) {
            return "Blocked: {$phone} is on the suppression list (" . ($reason ?: 'no reason given') . "). "
                 . "Release it on the Suppression tab to send again. Nothing was sent (₦0).";
        }
    } catch (PDOException $e) { /* table may not exist yet — ignore */ }

    return null;
}

/**
 * Auto-suppression: after SMS_SUPPRESS_AFTER consecutive carrier-confirmed
 * failures (no delivery in between) a number is suppressed so the church stops
 * paying for it. The Suppression tab always promised this; nothing did it.
 * Only carrier reports count — a send BulkSMS refused (e.g. empty wallet) has
 * no message_id and never suppresses anyone.
 */
function sms_evaluate_suppression(PDO $pdo, $phone, $lastRaw = '') {
    if (!sms_table_exists($pdo, 'sms_suppression')) return false;
    $st = $pdo->prepare("SELECT status, created_at FROM sms_log
                         WHERE recipient_phone = ? AND message_id IS NOT NULL AND message_id <> ''
                           AND status IN ('delivered','failed')
                         ORDER BY id DESC LIMIT " . (int)SMS_SUPPRESS_AFTER);
    $st->execute([$phone]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < SMS_SUPPRESS_AFTER) return false;
    foreach ($rows as $r) if ($r['status'] !== 'failed') return false;
    $firstFailed = end($rows)['created_at'];

    $reason = 'Auto: ' . SMS_SUPPRESS_AFTER . ' delivery failures in a row' . ($lastRaw !== '' ? ' (last: ' . sms_cut($lastRaw, 60) . ')' : '');
    $ex = $pdo->prepare("SELECT id, released_at FROM sms_suppression WHERE phone = ? ORDER BY id DESC LIMIT 1");
    $ex->execute([$phone]);
    $row = $ex->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['released_at'] === null) {
        $pdo->prepare("UPDATE sms_suppression SET consecutive_failures = GREATEST(consecutive_failures, ?), last_failed_at = NOW() WHERE id = ?")
            ->execute([SMS_SUPPRESS_AFTER, $row['id']]);
        return false;
    }
    if ($row) {
        // Released by a user: only re-suppress on failures that happened after the release.
        if (strtotime($firstFailed) <= strtotime($row['released_at'])) return false;
        $pdo->prepare("UPDATE sms_suppression
                       SET reason = ?, consecutive_failures = ?, first_failed_at = ?, last_failed_at = NOW(),
                           suppressed_at = NOW(), released_at = NULL, released_by = NULL,
                           note = 'Re-suppressed automatically after the number was released.'
                       WHERE id = ?")
            ->execute([$reason, SMS_SUPPRESS_AFTER, $firstFailed, $row['id']]);
    } else {
        $pdo->prepare("INSERT INTO sms_suppression (phone, reason, consecutive_failures, first_failed_at, last_failed_at, suppressed_at, note)
                       VALUES (?, ?, ?, ?, NOW(), NOW(), 'Added automatically by SMS Studio.')")
            ->execute([$phone, $reason, SMS_SUPPRESS_AFTER, $firstFailed]);
    }
    return true;
}

/* ============================================================================
 * STATUS MOVEMENTS (sms_status_events)
 * source: studio (a user in SMS Studio) | worker (queue worker) | bulksms (the
 *         answer to our submit call) | webhook | poll | system
 * ========================================================================== */
function sms_log_event(PDO $pdo, $logId, $from, $to, $source, array $x = []) {
    if (!$logId || !sms_table_exists($pdo, 'sms_status_events')) return;
    try {
        $pdo->prepare("INSERT INTO sms_status_events
                (sms_log_id, from_status, to_status, source, raw_status, api_code, detail, provider_time, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                (int)$logId, $from, $to, $source,
                isset($x['raw']) && $x['raw'] !== '' ? sms_cut($x['raw'], 191) : null,
                isset($x['code']) && $x['code'] !== '' ? sms_cut($x['code'], 32) : null,
                $x['detail'] ?? null,
                isset($x['provider_time']) && $x['provider_time'] !== '' ? sms_cut($x['provider_time'], 40) : null,
                $x['user_id'] ?? null,
            ]);
    } catch (PDOException $e) {
        error_log('SMS: could not record status event for log ' . $logId . ': ' . $e->getMessage());
    }
}

/* ============================================================================
 * SEND ONE MESSAGE
 * $opts: queue_id (sms_queue row being worked), source (event source label),
 *        skip_duplicate (explicit resend of a failed/unknown message)
 * ========================================================================== */
function sms_send_one($pdo, array $settings, array $recipient, $template, $campaignId, $userId, array $opts = []) {
    $source   = $opts['source'] ?? 'studio';
    $queueId  = $opts['queue_id'] ?? null;
    $rawPhone = trim((string)($recipient['phone'] ?? ''));
    $phone    = sms_normalize_phone($rawPhone);
    $name     = sms_cut(sms_clean_name($recipient['name'] ?? ''), 190);
    $srcType  = isset($recipient['source']) ? sms_cut((string)$recipient['source'], 20) : null;
    $srcId    = isset($recipient['source_id']) && is_numeric($recipient['source_id']) ? (int)$recipient['source_id'] : null;
    $senderId = (string)($settings['sender_id'] ?? '');
    $gateway  = in_array($settings['gateway'] ?? '', SMS_GATEWAYS, true) ? $settings['gateway'] : 'direct-refund';
    $body     = sms_render($template, $recipient);
    $ready    = sms_schema_ready($pdo);
    $linkQueue = function ($logId) use ($pdo, $queueId, $ready) {
        if ($queueId && $ready) $pdo->prepare("UPDATE sms_queue SET log_id = ? WHERE id = ?")->execute([$logId, $queueId]);
    };

    // (a) Refused before reaching BulkSMS — recorded so History shows every person, ₦0
    $refusal = null; $refStatus = 'failed';
    if ($phone === null) {
        $refusal = 'Invalid Nigerian mobile number "' . sms_cut($rawPhone, 40) . '". Nothing was sent (₦0).';
    } elseif ($body === '') {
        $refusal = 'The message is empty once the merge fields were filled in. Nothing was sent (₦0).';
    } elseif ($blocked = sms_send_guard($pdo, $phone, $body, !empty($opts['skip_duplicate']))) {
        $refusal = $blocked; $refStatus = 'blocked';
    }
    if ($refusal !== null) {
        $pdo->prepare("INSERT INTO sms_log
            (campaign_id, source_type, source_id, recipient_phone, recipient_name,
             sender_id, body, status, error_message, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$campaignId, $srcType, $srcId, sms_cut($phone ?? $rawPhone, 20), $name,
                       $senderId, $body, $refStatus, $refusal, $userId]);
        $logId = (int)$pdo->lastInsertId();
        sms_log_event($pdo, $logId, null, $refStatus, $source, ['detail' => $refusal, 'user_id' => $userId]);
        $linkQueue($logId);
        return ['status'=>$refStatus, 'phone'=>$phone ?? $rawPhone, 'error'=>$refusal, 'message_id'=>null, 'cost'=>null, 'log_id'=>$logId];
    }

    // (b) Record the attempt BEFORE the billable call (only once the schema supports 'queued')
    $logId = null;
    if ($ready) {
        $pdo->prepare("INSERT INTO sms_log
            (campaign_id, source_type, source_id, recipient_phone, recipient_name, sender_id, gateway_used, body, status, created_by)
            VALUES (?,?,?,?,?,?,?,?, 'queued', ?)")
            ->execute([$campaignId, $srcType, $srcId, $phone, $name, $senderId, $gateway, $body, $userId]);
        $logId = (int)$pdo->lastInsertId();
        sms_log_event($pdo, $logId, null, 'queued', $source, ['detail' => 'Handing the message to BulkSMS (' . $gateway . ' route).', 'user_id' => $userId]);
        $linkQueue($logId);
    }

    // (c) The billable call — NEVER retried (a lost response must not become two SMS)
    $payload = [
        // Single, clean v2 payload (official API reference).
        // NOTE: do NOT add 'sender'/'message'/'recipients' alongside these —
        // sending duplicate recipient keys can double-submit or trip carrier
        // anti-spam flood protection.
        'from'    => $senderId,
        'to'      => $phone,
        'body'    => $body,
        'gateway' => $gateway,
    ];
    if (!empty($settings['webhook_url'])) $payload['callback_url'] = $settings['webhook_url'];
    $res = sms_http('POST', sms_base_url($settings) . '/sms', $settings['api_token'] ?? '', $payload, 0, false);

    // (d) Classify the answer
    $apiCode = null; $messageId = null; $status = 'failed'; $cost = null; $units = null; $errMsg = null;
    $gatewayUsed = $gateway; $rawResponse = $res['data'] !== null ? json_encode($res['data']) : $res['raw'];
    if (!$res['ok']) {
        // 6/7 = could not resolve / connect, 35/60 = TLS handshake / certificate: the request never left.
        if (in_array((int)$res['errno'], [5, 6, 7, 35, 60], true)) {
            $errMsg = 'Could not reach BulkSMS (' . $res['message'] . '). Nothing was sent.';
        } else {
            $status = 'unknown';
            $errMsg = 'No answer from BulkSMS (' . $res['message'] . '). The message MAY have gone out — check the BulkSMS dashboard before resending.';
        }
    } elseif (sms_http_ok($res)) {
        $d = $res['data'];
        $dd = $d['data'] ?? [];
        if (is_array($dd) && isset($dd[0]) && is_array($dd[0])) $dd = $dd[0];
        $status = 'sent';
        $messageId = $dd['message_id'] ?? ($dd['id'] ?? ($d['message_id'] ?? null));
        $cost = $dd['cost'] ?? ($dd['total_cost'] ?? ($d['cost'] ?? null));
        $units = $dd['units'] ?? ($dd['pages'] ?? null);
        $gatewayUsed = $dd['gateway_used'] ?? $gateway;
        $apiCode = $d['code'] ?? null;
        if ($messageId === null || $messageId === '') {
            $errMsg = 'Accepted by BulkSMS, but no message ID came back, so delivery cannot be tracked.';
        }
    } elseif ($res['status'] >= 500 && $res['data'] === null) {
        $status = 'unknown';
        $errMsg = 'BulkSMS answered HTTP ' . $res['status'] . ' without a readable reply. The message MAY have gone out — check the BulkSMS dashboard before resending.';
    } else {
        $apiCode = is_array($res['data']) ? ($res['data']['code'] ?? null) : null;
        $errMsg = sms_api_error($res);
    }
    $messageId = $messageId !== null ? sms_cut((string)$messageId, 128) : null;
    $cost = is_numeric($cost) ? (float)$cost : null;
    $units = is_numeric($units) ? (int)$units : null;

    // (e) Persist
    if ($logId) {
        $pdo->prepare("UPDATE sms_log SET status = ?, gateway_used = ?, api_code = ?, message_id = ?, cost = ?, units = ?,
                              error_message = ?, raw_response = ?, updated_at = NOW()
                       WHERE id = ?")
            ->execute([$status, sms_cut($gatewayUsed, 40), $apiCode, $messageId, $cost, $units, $errMsg, $rawResponse, $logId]);
    } else {
        $pdo->prepare("INSERT INTO sms_log
            (campaign_id, source_type, source_id, recipient_phone, recipient_name, sender_id, gateway_used, body, status, api_code, message_id, cost, units, error_message, raw_response, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$campaignId, $srcType, $srcId, $phone, $name, $senderId, sms_cut($gatewayUsed, 40), $body, $status,
                       $apiCode, $messageId, $cost, $units, $errMsg, $rawResponse, $userId]);
        $logId = (int)$pdo->lastInsertId();
    }
    $detail = $status === 'sent'
        ? 'Accepted by BulkSMS' . ($messageId ? ' · message ID ' . $messageId : '') . ($cost !== null ? ' · ₦' . $cost : '') . ' · route ' . $gatewayUsed
        : $errMsg;
    sms_log_event($pdo, $logId, $ready ? 'queued' : null, $status, 'bulksms', [
        'raw' => is_array($res['data']) ? (string)($res['data']['message'] ?? '') : '',
        'code' => $apiCode, 'detail' => $detail, 'user_id' => $userId,
    ]);
    return ['status'=>$status, 'phone'=>$phone, 'message_id'=>$messageId, 'error'=>$errMsg, 'cost'=>$cost, 'log_id'=>$logId];
}

/* ============================================================================
 * DELIVERY REPORTS — apply (webhook + poll share this)
 * ========================================================================== */
/**
 * Apply one carrier report to one sms_log row.
 *  - never moves a message backwards: a late "ACCEPTD" cannot undo "DELIVRD"
 *    (the webhook and the poller both used to overwrite blindly);
 *  - never turns a delivered message into failed;
 *  - records the movement with the carrier's own words and time;
 *  - a failure feeds auto-suppression.
 * Returns ['changed' => bool, 'status' => current status, 'mapped' => mapped|null].
 */
function sms_apply_dlr(PDO $pdo, array $row, $rawStatus, $source, array $x = []) {
    $raw = trim((string)$rawStatus);
    $mapped = sms_map_dlr_status($raw);
    $current = (string)$row['status'];
    $out = ['changed' => false, 'status' => $current, 'mapped' => $mapped];
    if ($mapped === null || $current === 'blocked') return $out;

    if ($mapped === 'delivered')   $allowed = $current !== 'delivered';
    elseif ($mapped === 'failed')  $allowed = !in_array($current, ['delivered', 'failed'], true);
    else                           $allowed = in_array($current, ['queued', 'sent', 'unknown'], true);

    $note = $mapped === 'delivered' ? null : sms_cut($raw, 191);
    if ($allowed) {
        $pdo->prepare("UPDATE sms_log SET status = ?, api_code = COALESCE(?, api_code), error_message = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$mapped, $x['code'] ?? null, $note, $row['id']]);
        sms_log_event($pdo, $row['id'], $current, $mapped, $source, [
            'raw' => $raw, 'code' => $x['code'] ?? null, 'provider_time' => $x['provider_time'] ?? null,
            'detail' => $x['detail'] ?? null, 'user_id' => $x['user_id'] ?? null,
        ]);
        $out['changed'] = true;
        $out['status'] = $mapped;
        if ($mapped === 'failed') sms_evaluate_suppression($pdo, $row['recipient_phone'], $raw);
    } elseif ($mapped === $current && $mapped === 'pending' && $raw !== (string)$row['error_message']) {
        // Still in transit, but the carrier's wording moved on (ACCEPTD -> ENROUTE): keep that step too.
        $pdo->prepare("UPDATE sms_log SET error_message = ?, updated_at = NOW() WHERE id = ?")->execute([$note, $row['id']]);
        sms_log_event($pdo, $row['id'], $current, $mapped, $source, [
            'raw' => $raw, 'provider_time' => $x['provider_time'] ?? null, 'user_id' => $x['user_id'] ?? null,
        ]);
    }
    return $out;
}

/** Pull [message_id, status, recipient, provider_time, code] items out of any DLR payload shape. */
function sms_extract_reports($data) {
    if (!is_array($data)) return [];
    $cands = [$data];
    foreach (['data', 'reports', 'recipients', 'messages', 'items'] as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) continue;
        if (array_is_list($data[$k])) { foreach ($data[$k] as $c) if (is_array($c)) $cands[] = $c; }
        else {
            $cands[] = $data[$k];
            foreach (['reports', 'recipients', 'messages', 'items'] as $k2) {
                if (isset($data[$k][$k2]) && is_array($data[$k][$k2]) && array_is_list($data[$k][$k2])) {
                    foreach ($data[$k][$k2] as $c) if (is_array($c)) $cands[] = $c;
                }
            }
        }
    }
    if (array_is_list($data)) foreach ($data as $c) if (is_array($c)) $cands[] = $c;

    $items = [];
    foreach ($cands as $c) {
        $mid = $c['message_id'] ?? ($c['messageId'] ?? ($c['msg_id'] ?? ($c['id'] ?? null)));
        $st  = $c['delivery_status'] ?? ($c['dlr_status'] ?? ($c['status'] ?? ($c['event'] ?? ($c['state'] ?? null))));
        if (!is_scalar($st) || trim((string)$st) === '') continue;
        $items[] = [
            'message_id'    => is_scalar($mid) && (string)$mid !== '' ? (string)$mid : null,
            'status'        => trim((string)$st),
            'recipient'     => is_scalar($c['recipient'] ?? ($c['to'] ?? ($c['phone'] ?? null))) ? (string)($c['recipient'] ?? ($c['to'] ?? ($c['phone'] ?? ''))) : '',
            'provider_time' => (string)($c['delivered_at'] ?? ($c['done_date'] ?? ($c['delivery_time'] ?? ($c['updated_at'] ?? ($c['timestamp'] ?? ($c['date'] ?? '')))))),
            'code'          => is_scalar($c['error_code'] ?? null) ? (string)$c['error_code'] : null,
        ];
    }
    // Prefer items whose status we understand (drop the envelope's "success").
    $known = array_values(array_filter($items, function ($i) { return sms_map_dlr_status($i['status']) !== null; }));
    return $known ?: $items;
}

/**
 * Ask BulkSMS for the delivery report of one sent message and apply it.
 * Returns ['ok'=>bool, 'status'=>current status, 'raw'=>carrier words|null, 'changed'=>bool, 'error'=>string|null]
 */
function sms_poll_delivery(PDO $pdo, array $settings, array $row, $source = 'poll', $userId = null) {
    $out = ['ok'=>false, 'status'=>$row['status'], 'raw'=>null, 'changed'=>false, 'error'=>null];
    if (empty($row['message_id'])) { $out['error'] = 'This message has no BulkSMS message ID, so there is no delivery report to fetch.'; return $out; }
    if (empty($settings['api_token'])) { $out['error'] = 'BulkSMS is not configured.'; return $out; }

    $res = sms_http('GET', sms_base_url($settings) . '/delivery-reports?message_id=' . urlencode($row['message_id']), $settings['api_token']);
    if (sms_column_exists($pdo, 'sms_log', 'dlr_checked_at')) {
        $pdo->prepare("UPDATE sms_log SET dlr_checked_at = NOW() WHERE id = ?")->execute([$row['id']]);
    }
    if (!sms_http_ok($res)) { $out['error'] = sms_api_error($res); return $out; }
    $out['ok'] = true;

    $mine = substr((string)$row['recipient_phone'], -10);
    $pick = null;
    foreach (sms_extract_reports($res['data']) as $rep) {
        if ($rep['message_id'] !== null && $rep['message_id'] !== (string)$row['message_id'] && strpos((string)$rep['message_id'], (string)$row['message_id']) === false) continue;
        $who = preg_replace('/\D/', '', $rep['recipient']);
        if ($who !== '' && substr($who, -10) !== $mine) continue;   // belongs to someone else in a batch
        if (sms_map_dlr_status($rep['status']) === null) {
            error_log("SMS: unmapped delivery_status '{$rep['status']}' for log id {$row['id']}");
            continue;
        }
        $pick = $rep; break;
    }
    if ($pick === null) {
        // Fallback to summary totals
        $sum = $res['data']['data']['summary'] ?? ($res['data']['summary'] ?? null);
        if (is_array($sum)) {
            $del = (int)($sum['delivered'] ?? 0); $fail = (int)($sum['failed'] ?? 0); $pend = (int)($sum['pending'] ?? 0);
            if ($del > 0 && $fail === 0 && $pend === 0)      $pick = ['status' => 'delivered (summary)', 'provider_time' => '', 'code' => null];
            elseif ($fail > 0 && $del === 0 && $pend === 0)  $pick = ['status' => 'failed (summary)', 'provider_time' => '', 'code' => null];
            elseif ($pend > 0 && $del === 0 && $fail === 0)  $pick = ['status' => 'pending (summary)', 'provider_time' => '', 'code' => null];
        }
    }
    if ($pick === null) return $out;
    $out['raw'] = $pick['status'];
    $a = sms_apply_dlr($pdo, $row, $pick['status'], $source, [
        'provider_time' => $pick['provider_time'], 'code' => $pick['code'], 'user_id' => $userId,
    ]);
    $out['changed'] = $a['changed'];
    $out['status'] = $a['status'];
    return $out;
}

/* ============================================================================
 * QUEUE PROCESSING (cron worker + "send from this page" fallback)
 * ========================================================================== */
/**
 * Send queued campaign messages for up to $budget seconds, then spend any time
 * left fetching delivery reports. Only one run at a time (MySQL named lock —
 * released automatically if the process dies), so an overrunning cron minute
 * can no longer pick up rows another run is still sending (double billing).
 */
function sms_process_queue(PDO $pdo, $budget = 50, $runSource = 'cron') {
    $start = microtime(true);
    $out = ['ran'=>false, 'locked'=>false, 'sent'=>0, 'failed'=>0, 'blocked'=>0, 'unknown'=>0,
            'recovered'=>0, 'polled'=>0, 'expired'=>0, 'remaining'=>0, 'message'=>''];
    if ((int)$pdo->query("SELECT GET_LOCK('" . SMS_WORKER_LOCK . "', 0)")->fetchColumn() !== 1) {
        $out['locked'] = true;
        $out['message'] = 'Another send run is already in progress.';
        return $out;
    }
    try {
        $out['ran'] = true;
        $failedKeys = [];
        $settings = sms_load_settings($pdo, $failedKeys);
        if (empty($settings['api_token'])) {
            $out['message'] = $failedKeys
                ? 'Stored BulkSMS credentials cannot be decrypted (SMS_VAULT_KEY changed or missing). Re-enter them in Settings.'
                : 'BulkSMS is not configured (no API token). Nothing was sent.';
            sms_worker_heartbeat($pdo, $runSource, $out);
            return $out;
        }

        $out['recovered'] = sms_recover_orphans($pdo);
        $touched = [];
        $seen = [];
        $lastSend = 0.0;
        $timeLeft = function () use ($start, $budget) { return $budget - (microtime(true) - $start); };

        while ($timeLeft() > 4) {
            $pdo->exec("UPDATE sms_queue SET status = 'sending', locked_at = NOW()
                        WHERE status = 'queued' ORDER BY id LIMIT " . (int)SMS_WORKER_CLAIM);
            $jobs = $pdo->query("SELECT * FROM sms_queue WHERE status = 'sending' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            if (!$jobs) break;
            foreach ($jobs as $job) {
                if (isset($seen[$job['id']])) {
                    // Its status update did not stick; never send the same job twice in one run.
                    $pdo->prepare("UPDATE sms_queue SET status = 'error', sent_status = 'unknown', error_message = 'Queue row could not be updated after sending; not retried.' WHERE id = ?")->execute([$job['id']]);
                    continue;
                }
                $seen[$job['id']] = true;
                if ($timeLeft() <= 4) {   // out of time: hand untouched jobs back
                    $pdo->prepare("UPDATE sms_queue SET status = 'queued', locked_at = NULL WHERE id = ? AND status = 'sending'")->execute([$job['id']]);
                    continue;
                }
                $touched[(int)$job['campaign_id']] = true;
                $gap = SMS_MIN_SEND_GAP - (microtime(true) - $lastSend);
                if ($gap > 0) usleep((int)($gap * 1000000));
                try {
                    $r = sms_process_job($pdo, $settings, $job);
                } catch (Throwable $e) {
                    error_log('SMS worker: job ' . $job['id'] . ' crashed: ' . $e->getMessage());
                    $pdo->prepare("UPDATE sms_queue SET status = 'error', sent_status = 'failed', error_message = ? WHERE id = ?")
                        ->execute(['Internal error: ' . sms_cut($e->getMessage(), 200), $job['id']]);
                    $r = ['status' => 'failed'];
                }
                $lastSend = microtime(true);
                $k = $r['status'] === 'sent' ? 'sent' : (in_array($r['status'], ['blocked', 'unknown'], true) ? $r['status'] : 'failed');
                if ($r['status'] !== 'cancelled') $out[$k]++;
            }
        }
        foreach (array_keys($touched) as $cid) sms_refresh_campaign($pdo, $cid);

        $out['expired'] = sms_expire_stale($pdo);
        if ($timeLeft() > 3) $out['polled'] = sms_poll_due($pdo, $settings, $timeLeft);
        $out['remaining'] = (int)$pdo->query("SELECT COUNT(*) FROM sms_queue WHERE status IN ('queued','sending')")->fetchColumn();
        $out['message'] = "{$out['sent']} sent, {$out['failed']} failed, {$out['blocked']} blocked, {$out['unknown']} unknown; "
                        . "{$out['polled']} delivery reports checked; {$out['remaining']} still queued.";
        sms_worker_heartbeat($pdo, $runSource, $out);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('" . SMS_WORKER_LOCK . "')");
    }
    return $out;
}

/** Send one sms_queue job. Returns the sms_send_one result (status may also be 'cancelled'). */
function sms_process_job(PDO $pdo, array $settings, array $job) {
    $c = $pdo->prepare("SELECT id, body_template, created_by, status FROM sms_campaigns WHERE id = ?");
    $c->execute([$job['campaign_id']]);
    $camp = $c->fetch(PDO::FETCH_ASSOC);
    if (!$camp) {
        $pdo->prepare("UPDATE sms_queue SET status = 'error', sent_status = 'failed', error_message = 'Campaign no longer exists' WHERE id = ?")->execute([$job['id']]);
        return ['status' => 'failed'];
    }
    if ($camp['status'] === 'cancelled') {
        $pdo->prepare("UPDATE sms_queue SET status = 'cancelled', error_message = 'Campaign cancelled before this message was sent' WHERE id = ?")->execute([$job['id']]);
        return ['status' => 'cancelled'];
    }
    $recipient = json_decode((string)$job['recipient_json'], true);
    if (!is_array($recipient) || empty($recipient['phone'])) {
        $pdo->prepare("UPDATE sms_queue SET status = 'error', sent_status = 'failed', error_message = 'Invalid recipient data' WHERE id = ?")->execute([$job['id']]);
        return ['status' => 'failed'];
    }
    // created_by = whoever queued the campaign (was NULL, so History never showed who sent it)
    $r = sms_send_one($pdo, $settings, $recipient, $camp['body_template'], (int)$job['campaign_id'],
                      $camp['created_by'] !== null ? (int)$camp['created_by'] : null,
                      ['queue_id' => (int)$job['id'], 'source' => 'worker']);
    $qStatus = $r['status'] === 'sent' ? 'done' : 'error';
    $pdo->prepare("UPDATE sms_queue SET status = ?, sent_status = ?, error_message = ? WHERE id = ?")
        ->execute([$qStatus, $r['status'], $r['error'], $job['id']]);
    return $r;
}

/**
 * Rows left in 'sending' by a run that died (we hold the worker lock, so no
 * live run owns them). Never attempted -> back to the queue. Attempted ->
 * settle from the sms_log row; if the BulkSMS call itself was in flight the
 * outcome is 'unknown' and it is NOT re-sent (it may already be on a phone).
 */
function sms_recover_orphans(PDO $pdo) {
    $rows = $pdo->query("SELECT * FROM sms_queue WHERE status = 'sending'")->fetchAll(PDO::FETCH_ASSOC);
    $hasLogId = sms_column_exists($pdo, 'sms_queue', 'log_id');
    $n = 0;
    foreach ($rows as $job) {
        $log = null;
        if ($hasLogId && !empty($job['log_id'])) {
            $st = $pdo->prepare("SELECT id, status FROM sms_log WHERE id = ?");
            $st->execute([$job['log_id']]);
            $log = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (!$hasLogId) {
            $rec = json_decode((string)$job['recipient_json'], true);
            $ph = sms_normalize_phone(is_array($rec) ? ($rec['phone'] ?? '') : '');
            if ($ph !== null && $job['locked_at']) {
                $st = $pdo->prepare("SELECT id, status FROM sms_log WHERE campaign_id = ? AND recipient_phone = ?
                                       AND created_at >= (? - INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1");
                $st->execute([$job['campaign_id'], $ph, $job['locked_at']]);
                $log = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if ($log === null) {
            $pdo->prepare("UPDATE sms_queue SET status = 'queued', locked_at = NULL WHERE id = ? AND status = 'sending'")->execute([$job['id']]);
        } elseif ($log['status'] === 'queued') {
            $why = 'The sending process stopped while this message was being handed to BulkSMS. It may or may not have gone out — check the BulkSMS dashboard before resending.';
            $pdo->prepare("UPDATE sms_log SET status = 'unknown', error_message = ?, updated_at = NOW() WHERE id = ? AND status = 'queued'")->execute([$why, $log['id']]);
            sms_log_event($pdo, $log['id'], 'queued', 'unknown', 'system', ['detail' => $why]);
            $pdo->prepare("UPDATE sms_queue SET status = 'error', sent_status = 'unknown', error_message = ? WHERE id = ?")->execute([$why, $job['id']]);
        } else {
            $ok = in_array($log['status'], ['sent', 'pending', 'delivered'], true);
            $pdo->prepare("UPDATE sms_queue SET status = ?, sent_status = ? WHERE id = ?")
                ->execute([$ok ? 'done' : 'error', $ok ? 'sent' : $log['status'], $job['id']]);
        }
        $n++;
    }
    foreach (array_unique(array_column($rows, 'campaign_id')) as $cid) sms_refresh_campaign($pdo, (int)$cid);
    return $n;
}

function sms_refresh_campaign(PDO $pdo, $cid) {
    $pdo->prepare("UPDATE sms_campaigns
        SET sent_count = (SELECT COUNT(*) FROM sms_queue WHERE campaign_id = ? AND sent_status = 'sent'),
            failed_count = (SELECT COUNT(*) FROM sms_queue WHERE campaign_id = ? AND status = 'error')
        WHERE id = ?")->execute([$cid, $cid, $cid]);
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_queue WHERE campaign_id = ? AND status IN ('queued','sending')");
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("UPDATE sms_campaigns SET status = 'sent' WHERE id = ? AND status <> 'cancelled'")->execute([$cid]);
    } elseif (sms_schema_ready($pdo)) {
        $pdo->prepare("UPDATE sms_campaigns SET status = 'sending' WHERE id = ? AND status = 'queued'")->execute([$cid]);
    }
}

/**
 * Give up on messages that never got a final report: after SMS_DLR_GIVE_UP_HOURS
 * (and, when polling is possible, at least one look-up made after that age)
 * 'sent' / 'pending' becomes 'unknown' instead of "awaiting" for ever.
 */
function sms_expire_stale(PDO $pdo) {
    $canPoll = sms_column_exists($pdo, 'sms_log', 'dlr_checked_at');
    $h = (int)SMS_DLR_GIVE_UP_HOURS;
    $sql = "SELECT id, status FROM sms_log
            WHERE status IN ('sent','pending') AND created_at < (NOW() - INTERVAL {$h} HOUR)";
    if ($canPoll) {
        $sql .= " AND (message_id IS NULL OR message_id = ''
                       OR created_at < (NOW() - INTERVAL 30 DAY)
                       OR (dlr_checked_at IS NOT NULL AND dlr_checked_at > (created_at + INTERVAL {$h} HOUR)))";
    }
    $rows = $pdo->query($sql . " ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $why = "No final delivery report from BulkSMS within {$h} hours.";
    foreach ($rows as $r) {
        $st = $pdo->prepare("UPDATE sms_log SET status = 'unknown', error_message = ?, updated_at = NOW() WHERE id = ? AND status IN ('sent','pending')");
        $st->execute([$why, $r['id']]);
        if ($st->rowCount()) sms_log_event($pdo, $r['id'], $r['status'], 'unknown', 'system', ['detail' => $why]);
    }
    return count($rows);
}

/** Fetch delivery reports for messages that are due a look-up (newest first, with back-off). */
function sms_poll_due(PDO $pdo, array $settings, callable $timeLeft) {
    if (!sms_column_exists($pdo, 'sms_log', 'dlr_checked_at')) return 0;
    $rows = $pdo->query("SELECT * FROM sms_log
        WHERE status IN ('sent','pending') AND message_id IS NOT NULL AND message_id <> ''
          AND created_at < (NOW() - INTERVAL 1 MINUTE) AND created_at > (NOW() - INTERVAL 30 DAY)
          AND (dlr_checked_at IS NULL
               OR (created_at > (NOW() - INTERVAL 1 HOUR)  AND dlr_checked_at < (NOW() - INTERVAL 2 MINUTE))
               OR (created_at > (NOW() - INTERVAL 24 HOUR) AND dlr_checked_at < (NOW() - INTERVAL 15 MINUTE))
               OR dlr_checked_at < (NOW() - INTERVAL 6 HOUR))
        ORDER BY created_at DESC LIMIT " . (int)SMS_POLLS_PER_RUN)->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $row) {
        if ($timeLeft() <= 3) break;
        sms_poll_delivery($pdo, $settings, $row, 'poll');
        $n++;
        usleep(500000);   // stay well inside the 120 requests/minute limit
    }
    return $n;
}

function sms_worker_heartbeat(PDO $pdo, $runSource, array $out) {
    if (!sms_table_exists($pdo, 'sms_worker_heartbeat')) return;
    $isCron = $runSource === 'cron' ? 1 : 0;
    $pdo->prepare("INSERT INTO sms_worker_heartbeat (id, last_run_at, last_cron_at, last_source, last_sent, last_failed, last_polled, last_message)
                   VALUES (1, NOW(), IF(? = 1, NOW(), NULL), ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE last_run_at = NOW(), last_cron_at = IF(? = 1, NOW(), last_cron_at),
                       last_source = ?, last_sent = ?, last_failed = ?, last_polled = ?, last_message = ?")
        ->execute([$isCron, $runSource, $out['sent'], $out['failed'], $out['polled'], sms_cut($out['message'], 255),
                   $isCron, $runSource, $out['sent'], $out['failed'], $out['polled'], sms_cut($out['message'], 255)]);
}

/** What the Studio status bar shows: queue depth, cron freshness, webhook + vault health. */
function sms_health(PDO $pdo) {
    $h = [
        'vault_ok' => sms_vault_ready(), 'configured' => false, 'decrypt_failed' => [],
        'queued' => 0, 'sending' => 0, 'oldest_queued_at' => null,
        'last_run_at' => null, 'last_cron_at' => null, 'cron_age_sec' => null, 'last_message' => null,
        'webhook_secret_set' => sms_webhook_secret() !== '', 'webhook_url_set' => false, 'last_webhook_at' => null,
        'schema_ready' => sms_schema_ready($pdo), 'now' => date('Y-m-d H:i:s'),
    ];
    $failed = [];
    $s = sms_load_settings($pdo, $failed);
    $h['configured'] = !empty($s['api_token']);
    $h['decrypt_failed'] = $failed;
    $h['webhook_url_set'] = !empty($s['webhook_url']);
    $q = $pdo->query("SELECT SUM(status = 'queued') AS queued, SUM(status = 'sending') AS sending FROM sms_queue")->fetch(PDO::FETCH_ASSOC);
    $h['queued'] = (int)($q['queued'] ?? 0);
    $h['sending'] = (int)($q['sending'] ?? 0);
    if ($h['queued'] > 0) {
        $h['oldest_queued_at'] = $pdo->query("SELECT MIN(c.created_at) FROM sms_queue q JOIN sms_campaigns c ON c.id = q.campaign_id WHERE q.status = 'queued'")->fetchColumn() ?: null;
    }
    if (sms_table_exists($pdo, 'sms_worker_heartbeat')) {
        $hb = $pdo->query("SELECT last_run_at, last_cron_at, last_message, TIMESTAMPDIFF(SECOND, last_cron_at, NOW()) AS cron_age
                           FROM sms_worker_heartbeat WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if ($hb) {
            $h['last_run_at'] = $hb['last_run_at'];
            $h['last_cron_at'] = $hb['last_cron_at'];
            $h['cron_age_sec'] = $hb['cron_age'] !== null ? (int)$hb['cron_age'] : null;
            $h['last_message'] = $hb['last_message'];
        }
    }
    if (sms_table_exists($pdo, 'sms_webhook_log')) {
        $col = sms_column_exists($pdo, 'sms_webhook_log', 'received_at') ? 'received_at'
             : (sms_column_exists($pdo, 'sms_webhook_log', 'created_at') ? 'created_at' : null);
        if ($col) $h['last_webhook_at'] = $pdo->query("SELECT MAX($col) FROM sms_webhook_log")->fetchColumn() ?: null;
    }
    return $h;
}
