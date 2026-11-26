-- 20261224090000_auth_passkeys.sql
-- Passkeys (owner, 26 Nov 2026): sign in with the phone's own fingerprint, face or PIN (WebAuthn).
-- One row per passkey. The private key never leaves the person's device (or their iCloud/Google
-- password manager, which syncs it); we keep only the public key (PEM), the credential id and the
-- signature counter. user_handle is a random id the device stores with the passkey, the same for
-- every passkey of one person, so a passkey sign-in names its account without an email.
-- includes/passkeys.php, api/passkey_api.php. Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS auth_passkeys (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    credential_id VARCHAR(255) NOT NULL,
    user_handle VARCHAR(64) NOT NULL,
    public_key TEXT NOT NULL,
    algorithm INT NOT NULL,
    sign_count BIGINT NOT NULL DEFAULT 0,
    transports VARCHAR(100) NULL DEFAULT NULL,
    backed_up TINYINT NOT NULL DEFAULT 0,
    label VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    created_ip VARCHAR(45) NULL DEFAULT NULL,
    last_used_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_passkeys_credential (credential_id),
    KEY ix_auth_passkeys_user (user_id),
    KEY ix_auth_passkeys_handle (user_handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
