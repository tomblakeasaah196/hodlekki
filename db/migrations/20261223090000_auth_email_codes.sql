-- 20261223090000_auth_email_codes.sql
-- Six-digit codes sent by email (owner, 21 Nov 2026: the QR entry to a standalone test, test.php):
--   reset    a forgotten password: the code goes to the CONFIRMED personal email, then the new password signs them in;
--   confirm  after the test: the code goes to the email they gave, and confirms it.
-- The code itself is never stored, only its password_hash(). A code works once, for 15 minutes, and dies after 5 wrong
-- tries; sends are limited per person and per IP (includes/auth_codes.php). Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS auth_email_codes (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    purpose VARCHAR(12) NOT NULL,
    email VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    tries INT NOT NULL DEFAULT 0,
    ip VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_auth_email_codes_user (user_id, purpose, created_at),
    KEY ix_auth_email_codes_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
