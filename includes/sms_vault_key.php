<?php
/**
 * SMS STUDIO — Vault Key Loader
 * ---------------------------------------------------------------------------
 * The AES-256-GCM key used to encrypt BulkSMS connection details at rest in
 * the `sms_settings` table now lives in .env (SMS_VAULT_KEY), not in source
 * control. This file only reads it and exposes it via the SMS_VAULT_KEY
 * constant that the rest of the SMS Studio code already expects.
 *
 * Generate a fresh key with:
 *     php -r "echo bin2hex(random_bytes(32));"
 *
 * Rotating the key invalidates any ciphertext already stored in
 * `sms_settings`, so re-encrypt or re-enter the BulkSMS tokens afterwards.
 */

// includes/db.php loads .env into $_ENV. If SMS Studio pages are hit before
// db.php has run in this request, pull in the loader ourselves.
if (!isset($_ENV['SMS_VAULT_KEY'])) {
    require_once __DIR__ . '/db.php';
}

$smsVaultKey = trim($_ENV['SMS_VAULT_KEY'] ?? '');

if (!defined('SMS_VAULT_KEY')) {
    define('SMS_VAULT_KEY', $smsVaultKey);
}

// Fail loudly rather than silently mis-encrypting when the key is missing
// or the wrong length. Skip the guard on CLI so cron/one-off scripts that
// don't need SMS still boot.
if (PHP_SAPI !== 'cli' && (SMS_VAULT_KEY === '' || strlen(SMS_VAULT_KEY) !== 64)) {
    http_response_code(500);
    exit('SMS Studio is not configured: set SMS_VAULT_KEY in .env to a 64-hex-character value.');
}
