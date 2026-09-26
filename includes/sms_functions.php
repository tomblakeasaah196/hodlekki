<?php
/**
 * SMS STUDIO — Shared helper functions (used by sms_api.php and the queue worker)
 */
require_once __DIR__ . '/sms_vault_key.php';  // defines SMS_VAULT_KEY for decryption
/* ============================================================================
 * ENCRYPTION HELPERS (AES-256-GCM)
 * ========================================================================== */
function sms_key_bytes() {
    return hex2bin(SMS_VAULT_KEY); // 32 bytes
}
function sms_encrypt($plain) {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt((string)$plain, 'aes-256-gcm', sms_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return null;
    return [
        'enc' => base64_encode($cipher),
        'iv'  => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}
function sms_decrypt($enc, $iv, $tag) {
    if ($enc === null || $enc === '') return '';
    $raw = openssl_decrypt(base64_decode($enc), 'aes-256-gcm', sms_key_bytes(), OPENSSL_RAW_DATA, base64_decode($iv), base64_decode($tag));
    return $raw === false ? '' : $raw;
}

/* ============================================================================
 * SETTINGS LOAD / SAVE (encrypted rows)
 * ========================================================================== */
const SMS_KEYS = ['base_url', 'api_token', 'sender_id', 'gateway', 'webhook_url'];

function sms_load_settings(PDO $pdo) {
    $out = [];
    $rows = $pdo->query("SELECT skey, enc_value, iv, tag FROM sms_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $out[$r['skey']] = sms_decrypt($r['enc_value'], $r['iv'], $r['tag']);
    }
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

/* ============================================================================
 * AUDIENCE QUERIES
 * ========================================================================== */
function sms_audience_users(PDO $pdo, $statuses, $search, $cap = 2000) {
    $sql = "SELECT id, first_name, last_name, phone, email, spiritual_status
            FROM users
            WHERE phone IS NOT NULL AND TRIM(phone) <> ''
              AND COALESCE(attendance_status,'') <> 'Relocated'";
    $params = [];
    // spiritual_status values are strings
    if (!empty($statuses) && is_array($statuses)) {
        $ph = [];
        foreach ($statuses as $i => $s) { $ph[] = ':st'.$i; $params['st'.$i] = $s; }
        $sql .= " AND spiritual_status IN (" . implode(',', $ph) . ")";
    }
    if (!empty($search)) {
        $sql .= " AND (CONCAT(first_name,' ',last_name) LIKE :q1 OR phone LIKE :q2 OR email LIKE :q3)";
        $params['q1'] = "%{$search}%";
        $params['q2'] = "%{$search}%";
        $params['q3'] = "%{$search}%";
    }
    $sql .= " ORDER BY first_name ASC LIMIT " . (int)$cap;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    $seen = [];
    $dropped = 0;
    $shared = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $normPhone = sms_normalize_phone($u['phone']);
        if ($normPhone === null) { $dropped++; continue; } // skip invalid/empty
        if (isset($seen[$normPhone])) {
            $shared[$normPhone][] = trim($u['first_name'] . ' ' . $u['last_name']);
            continue;
        }
        $seen[$normPhone] = true;
        $out[] = [
            'source' => 'user', 'source_id' => $u['id'],
            'name' => trim($u['first_name'] . ' ' . $u['last_name']),
            'phone' => $normPhone,
            'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
            'email' => $u['email'],
            'event_title' => '',
        ];
    }
    return ['data'=>$out, 'dropped'=>$dropped, 'shared'=>$shared];
}

/**
 * Check-ins audience: everyone who checked into an event (all days), deduped by
 * phone so Day-1+Day-2 attendees get ONE message. Uses their LATEST check-in's
 * blessing_ref. First name = the part before the first space in full_name.
 */
function sms_audience_checkins(PDO $pdo, $eventId, $search, $cap = 2000) {
    $sql = "SELECT c.full_name, c.phone, c.event_id,
                   c.blessing_ref, e.title AS event_title
            FROM checkins c
            LEFT JOIN events e ON e.id = c.event_id
            WHERE c.phone IS NOT NULL AND TRIM(c.phone) <> ''";
    $params = [];
    if (!empty($eventId)) { $sql .= " AND c.event_id = :eid"; $params['eid'] = $eventId; }
    if (!empty($search)) {
        $sql .= " AND (c.full_name LIKE :q1 OR c.phone LIKE :q2)";
        $params['q1'] = "%{$search}%";
        $params['q2'] = "%{$search}%";
    }
    $sql .= " ORDER BY c.id DESC";   // most recent check-in first
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = [];   // dedup by normalized phone -> keep first (latest) row
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $normPhone = sms_normalize_phone($r['phone']);
        if ($normPhone === null) continue;
        if (isset($seen[$normPhone])) continue;
        $seen[$normPhone] = true;

        $fullName = trim((string)$r['full_name']);
        $firstName = $fullName !== '' ? explode(' ', $fullName)[0] : '';
        $lastName = $fullName !== '' ? (strpos($fullName, ' ') !== false ? trim(substr($fullName, strpos($fullName, ' ')+1)) : '') : '';

        $out[] = [
            'source' => 'checkin', 'source_id' => null,
            'name' => $fullName ?: 'Guest',
            'phone' => $normPhone,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => '',
            'event_title' => $r['event_title'] ?? '',
            'blessing_ref' => $r['blessing_ref'] ?? '',
        ];
    }
    return $out;
}

function sms_audience_registrations(PDO $pdo, $eventId, $search, $cap = 2000) {
    $sql = "SELECT r.id AS reg_id, r.event_id, r.guest_name, r.guest_phone, r.guest_email, r.user_id, r.matched_user_id,
                   u.first_name, u.last_name, u.phone AS user_phone, u.email AS user_email,
                   e.title AS event_title
            FROM event_registrations r
            LEFT JOIN users u ON u.id = COALESCE(r.user_id, r.matched_user_id)
            LEFT JOIN events e ON e.id = r.event_id
            WHERE 1=1";
    $params = [];
    if (!empty($eventId)) { $sql .= " AND r.event_id = :eid"; $params['eid'] = $eventId; }
    if (!empty($search)) {
        $sql .= " AND (r.guest_name LIKE :q1 OR r.guest_phone LIKE :q2 OR r.guest_email LIKE :q3 OR CONCAT(u.first_name,' ',u.last_name) LIKE :q4)";
        $params['q1'] = "%{$search}%";
        $params['q2'] = "%{$search}%";
        $params['q3'] = "%{$search}%";
        $params['q4'] = "%{$search}%";
    }
    $sql .= " ORDER BY r.registered_at DESC LIMIT " . (int)$cap;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = []; // dedupe by NORMALIZED phone so 0902... and 234902... collapse to one
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $phone = trim($r['guest_phone'] !== null && $r['guest_phone'] !== '' ? $r['guest_phone'] : ($r['user_phone'] ?? ''));
        $normPhone = sms_normalize_phone($phone);
        if ($normPhone === null) continue;              // skip junk / empty
        $dedupeKey = $normPhone;                        // normalize to collapse variants
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;

        $firstName = $r['first_name'] ?: (explode(' ', (string)$r['guest_name'])[0] ?? '');
        $lastName  = $r['last_name']  ?: '';
        $name      = $r['first_name'] ? trim($r['first_name'] . ' ' . $r['last_name']) : ($r['guest_name'] ?? '');
        $email     = $r['guest_email'] ?: ($r['user_email'] ?? '');

        $out[] = [
            'source' => 'registration', 'source_id' => $r['reg_id'],
            'name' => $name ?: 'Guest',
            'phone' => $normPhone,                      // store normalized
            'first_name' => $firstName, 'last_name' => $lastName,
            'email' => $email,
            'guest_name' => $r['guest_name'] ?? '',
            'event_title' => $r['event_title'] ?? '',
        ];
    }
    return $out;
}

/* ============================================================================
 * TEMPLATE RENDERING (merge fields)
 * ========================================================================== */
function sms_render($template, array $data) {
    $firstName = $data['first_name'] ?? '';
    $lastName  = $data['last_name'] ?? '';

    // Resolve the guest's full name, then take only the FIRST name (up to the first space)
    $fullGuest = trim((string)($data['guest_name'] ?? ''));
    if ($fullGuest === '' && $firstName !== '') {
        $fullGuest = trim($firstName . ' ' . $lastName);
    } elseif ($fullGuest === '') {
        $fullGuest = trim((string)($data['name'] ?? ''));
    }
    $guestFirstName = $fullGuest !== '' ? explode(' ', $fullGuest)[0] : '';

    $map = [
        '{{first_name}}' => $firstName,
        '{{last_name}}'  => $lastName,
        '{{full_name}}'  => trim($firstName . ' ' . $lastName),
        '{{guest_name}}' => $guestFirstName,
        '{{name}}'       => $fullGuest !== '' ? $fullGuest : ($data['name'] ?? ''),
        '{{phone}}'      => $data['phone'] ?? '',
        '{{email}}'      => $data['email'] ?? '',
        '{{event_title}}'=> $data['event_title'] ?? '',
        '{{blessing_ref}}'=> $data['blessing_ref'] ?? '',
    ];
    return strtr((string)$template, $map);
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST' && $payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Transient failures: retry with exponential backoff (429 rate-limit / 5xx server / timeouts).
    // NOTE: billable POSTs pass allowRetry=false — a lost response must NOT be re-sent,
    // otherwise one send becomes multiple billed messages.
    $retryable = $allowRetry && (($body === false) || in_array($code, [429, 500, 502, 503, 504], true));
    if ($retryable && $attempt < 2) {
        sleep(1 + $attempt); // 1s, then 2s
        return sms_http($method, $url, $token, $payload, $attempt + 1, $allowRetry);
    }

    if ($body === false) return ['ok'=>false, 'status'=>0, 'message'=>$err, 'data'=>null];
    $json = json_decode($body, true);
    return ['ok'=>true, 'status'=>$code, 'data'=>is_array($json) ? $json : null];
}

/**
 * Normalize any Nigerian phone to international 234XXXXXXXXXX and validate it.
 * Accepts: 08012345678, 8012345678, +2348012345678, 2348012345678.
 * Returns the normalized number, or null if it is not a valid NG mobile.
 */
function sms_normalize_phone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return null;
    if (strlen($p) === 11 && $p[0] === '0') {
        $p = '234' . substr($p, 1);            // 08012345678 -> 2348012345678
    } elseif (strlen($p) === 10) {
        $p = '234' . $p;                        // 8012345678   -> 2348012345678
    } elseif (strlen($p) === 13 && substr($p, 0, 3) !== '234') {
        $p = '234' . ltrim($p, '0');            // odd 13-digit non-234 form
    }
    // valid NG mobile: 234 + [7/8/9] + [0/1] + 8 digits  (e.g. 2348012345678)
    return preg_match('/^234[789][01]\d{8}$/', $p) ? $p : null;
}

/**
 * Send guard: refuse sends that Nigerian carriers treat as spam.
 * This is the protection that stops duplicate-content / frequency-capping blocks.
 * Returns a human-readable block reason, or null to proceed.
 */
function sms_send_guard(PDO $pdo, $phone, $body, $isTest = false) {

    // 1) Identical body to the same handset inside 24h (duplicate-content filter)
    if (!$isTest) {
        $st = $pdo->prepare("SELECT created_at FROM sms_log
                             WHERE recipient_phone = ? AND body = ?
                               AND created_at > (NOW() - INTERVAL 24 HOUR)
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$phone, $body]);
        if ($prev = $st->fetchColumn()) {
            return "Blocked: this exact message already went to {$phone} at {$prev}. "
                 . "Carriers drop duplicates. Change the wording or wait 24 hours.";
        }
    }

    // 2) Burst cap — max 3 messages to one handset per hour
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_log
                         WHERE recipient_phone = ?
                           AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $st->execute([$phone]);
    if ((int)$st->fetchColumn() >= 3) {
        return "Blocked: 3 messages already sent to {$phone} in the past hour. "
             . "Airtel and MTN throttle at this rate.";
    }

    // 3) Suppression list (if the table exists)
    try {
        $st = $pdo->prepare("SELECT reason FROM sms_suppression
                             WHERE phone = ? AND released_at IS NULL LIMIT 1");
        $st->execute([$phone]);
        if ($reason = $st->fetchColumn()) {
            return "Blocked: {$phone} is suppressed ({$reason}). Release it in Settings to retry.";
        }
    } catch (PDOException $e) { /* table may not exist yet — ignore */ }

    return null;
}

function sms_send_one($pdo, array $settings, array $recipient, $template, $campaignId, $userId) {
    $phone = sms_normalize_phone($recipient['phone'] ?? '');
    if ($phone === null) {
        return ['status'=>'failed', 'phone'=>$recipient['phone'] ?? '', 'error'=>'Invalid Nigerian phone number: ' . ($recipient['phone'] ?? '')];
    }

    $body = sms_render($template, $recipient);

    // Send guard: block spammy sends BEFORE they reach BulkSMS (and before billing)
    if ($blocked = sms_send_guard($pdo, $phone, $body)) {
        $pdo->prepare("INSERT INTO sms_log
            (campaign_id, source_type, source_id, recipient_phone, recipient_name,
             sender_id, body, status, error_message, created_by)
            VALUES (?,?,?,?,?,?,?, 'blocked', ?, ?)")
            ->execute([$campaignId, $recipient['source'] ?? null, $recipient['source_id'] ?? null,
                       $phone, $recipient['name'] ?? '', $settings['sender_id'] ?? '',
                       $body, $blocked, $userId]);
        return ['status'=>'blocked','phone'=>$phone,'error'=>$blocked];
    }

    $senderId = $settings['sender_id'] ?: '';

    $payload = [
        // Single, clean v2 payload (official API reference).
        // NOTE: do NOT add 'sender'/'message'/'recipients' alongside these —
        // sending duplicate recipient keys can double-submit or trip carrier
        // anti-spam flood protection.
        'from' => $senderId,
        'to'   => $phone,
        'body' => $body,
    ];
    // Gateway: default to BulkSMS's recommended 'direct-refund' route (better
    // delivery + auto-refund of undelivered) when none is configured.
    $gateway = !empty($settings['gateway']) ? $settings['gateway'] : 'direct-refund';
    $payload['gateway'] = $gateway;
    if (!empty($settings['webhook_url'])) $payload['callback_url'] = $settings['webhook_url'];

    $base = rtrim($settings['base_url'] ?: 'https://www.bulksmsnigeria.com/api/v2', '/');
    // NEVER retry a billable POST: allowRetry=false
    $res = sms_http('POST', $base . '/sms', $settings['api_token'], $payload, 0, false);

    $apiCode = null; $messageId = null; $status = 'failed'; $cost = null; $units = null; $errMsg = null;
    $gatewayUsed = $gateway; $rawResponse = null;

    if ($res['ok'] && $res['data']) {
        $d = $res['data'];
        $rawResponse = json_encode($res['data']);
        if (($d['status'] ?? '') === 'success') {
            $status = 'sent';
            $messageId = $d['data']['id'] ?? ($d['data']['message_id'] ?? null);
            $cost = $d['data']['cost'] ?? ($d['data']['total_cost'] ?? null);
            $units = $d['data']['units'] ?? null;
            $gatewayUsed = $d['data']['gateway_used'] ?? $gateway;
        } else {
            $apiCode = $d['code'] ?? null;
            $errMsg = $d['message'] ?? ($d['error'] ?? null);
            if ($apiCode) {
                $explain = sms_explain_code($apiCode);
                if ($explain) $errMsg .= ' — ' . $explain;
            }
        }
    } else {
        $errMsg = $res['message'] ?? 'Network error';
        if (empty($res['status'])) $errMsg = ($errMsg ?: 'Request failed');
    }

    // log (gateway_used + raw_response columns added in the schema patch)
    $stmt = $pdo->prepare("INSERT INTO sms_log
        (campaign_id, source_type, source_id, recipient_phone, recipient_name, sender_id, gateway_used, body, status, api_code, message_id, cost, units, error_message, raw_response, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $campaignId, $recipient['source'] ?? null, $recipient['source_id'] ?? null,
        $phone, $recipient['name'] ?? '', $senderId, $gatewayUsed, $body, $status,
        $apiCode, $messageId, $cost !== null ? (float)$cost : null, $units,
        $errMsg, $rawResponse, $userId
    ]);
    return ['status'=>$status, 'phone'=>$phone, 'message_id'=>$messageId, 'error'=>$errMsg, 'cost'=>$cost];
}
