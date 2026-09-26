<?php
/**
 * ============================================================================
 * SMS STUDIO — Shared delivery-status & error-code vocabulary
 * File: /includes/sms_status.php
 * ----------------------------------------------------------------------------
 * Single source of truth for mapping BulkSMS / SMPP delivery-status strings to
 * our four states. Required by BOTH sms_api.php (poller) and sms_webhook.php so
 * the two paths can never disagree about the same message.
 *
 * Order matters: failure patterns are tested first, because 'undeliv' and
 * 'not delivered' both CONTAIN 'deliv'. An unrecognised status returns null —
 * we log it for a human rather than guess.
 * ============================================================================
 */

/**
 * Map any BulkSMS / SMPP delivery-status string to our states.
 * Returns 'delivered' | 'failed' | 'pending' | null (unrecognised — never guess).
 */
function sms_map_dlr_status($raw) {
    $s = strtolower(trim((string)$raw));
    if ($s === '') return null;

    // 1) FAILED first — 'undeliv' and 'not delivered' both contain 'deliv'
    foreach (['undeliv','not deliv','not_deliv','notdeliv','fail','expir',
              'reject','delet','block','dnd','invalid','unknown','absent'] as $n) {
        if (strpos($s, $n) !== false) return 'failed';
    }
    // 2) DELIVERED
    if (strpos($s, 'deliv') !== false) return 'delivered';
    // 3) INTERIM
    foreach (['sent','accept','pend','queue','submit','route','await'] as $n) {
        if (strpos($s, $n) !== false) return 'pending';
    }
    return null;
}

/**
 * Published BSNG error codes → plain English.
 * Returns null if the code is not recognised.
 */
function sms_explain_code($code) {
    static $map = [
        'BSNG-1000' => 'Authentication failed — invalid or missing API token.',
        'BSNG-1001' => 'API token expired — generate a new one.',
        'BSNG-1002' => 'Account suspended — contact BulkSMS support.',
        'BSNG-1003' => 'Account not verified — complete KYC verification.',
        'BSNG-2000' => 'Validation error — check the request parameters.',
        'BSNG-2001' => 'Invalid phone number format.',
        'BSNG-2002' => 'Message body is required.',
        'BSNG-2003' => 'Sender ID is required.',
        'BSNG-2004' => 'Invalid sender ID — must be 3-11 alphanumeric characters.',
        'BSNG-2005' => 'Message body exceeds the maximum length.',
        'BSNG-2006' => 'No valid recipients found.',
        'BSNG-2011' => 'Sender ID not registered or not approved.',
        'BSNG-3000' => 'Insufficient wallet balance — fund the account.',
        'BSNG-3001' => 'Transaction limit exceeded.',
        'BSNG-3002' => 'Minimum balance requirement not met.',
        'BSNG-4000' => 'SMS processing failed at the gateway.',
        'BSNG-4001' => 'SMS gateway unavailable — try again shortly.',
        'BSNG-4002' => 'Invalid gateway — this route may not be on your plan.',
        'BSNG-4003' => 'Rate limit exceeded — slow down (120 requests/minute).',
        'BSNG-5000' => 'BulkSMS internal server error.',
        'BSNG-5001' => 'BulkSMS service temporarily unavailable.',
    ];
    return $map[strtoupper((string)$code)] ?? null;
}
