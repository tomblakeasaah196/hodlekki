<?php
// /includes/sms_vault_key.php
/**
 * SMS STUDIO — Vault Key Loader
 * ---------------------------------------------------------------------------
 * The AES-256-GCM key used to encrypt BulkSMS connection details at rest in
 * the `sms_settings` table lives in .env (SMS_VAULT_KEY), not in source
 * control. This file only reads it and exposes it via the SMS_VAULT_KEY
 * constant that the rest of the SMS Studio code expects.
 *
 * Generate a fresh key with:
 *     php -r "echo bin2hex(random_bytes(32));"
 *
 * Rotating the key invalidates any ciphertext already stored in
 * `sms_settings`, so re-encrypt or re-enter the BulkSMS tokens afterwards.
 *
 * A missing or malformed key no longer kills the whole request with a plain
 * text page (that broke every JSON caller and the delivery webhook, which
 * does not need the key at all). Instead sms_vault_ready() reports it,
 * sms_key_bytes() throws before anything is encrypted or decrypted with a bad
 * key, and the Studio shows the problem in its status bar.
 */

// includes/db.php loads .env into $_ENV. If SMS Studio pages are hit before
// db.php has run in this request, pull in the loader ourselves.
if (!isset($_ENV['SMS_VAULT_KEY'])) {
    require_once __DIR__ . '/db.php';
}

if (!defined('SMS_VAULT_KEY')) {
    define('SMS_VAULT_KEY', trim((string)($_ENV['SMS_VAULT_KEY'] ?? '')));
}

/** True when SMS_VAULT_KEY is exactly 64 hex characters (32 bytes). */
function sms_vault_ready() {
    return strlen(SMS_VAULT_KEY) === 64 && ctype_xdigit(SMS_VAULT_KEY);
}
