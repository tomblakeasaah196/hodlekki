-- /db/migrations/20261129090000_campaigns_identity.sql
--
-- Email identity: password reset links sent to a person's confirmed personal
-- email, and the record of people who told the sign-in prompt they have no
-- email at all. Guide: docs/EMAIL.md ("Sign-in and reset").
--
-- Reset tokens are stored only as sha256; a link works once, for 30 minutes,
-- and any later password change (or a newer link) makes it useless. Requests
-- are throttled per address of the requester and per identifier typed, both
-- stored as hashes only.

CREATE TABLE IF NOT EXISTS auth_reset_tokens (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    sent_to VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_reset_tokens_hash (token_hash),
    KEY ix_auth_reset_tokens_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_reset_requests (
    id INT NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    who_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_auth_reset_requests_ip (ip_hash, created_at),
    KEY ix_auth_reset_requests_who (who_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "I don't have an email": the prompt asks again after ask_again_at. A worker
-- can add an address for the person later (that clears this row).
CREATE TABLE IF NOT EXISTS email_prompt_skips (
    user_id INT NOT NULL,
    reason VARCHAR(20) NOT NULL DEFAULT 'no_email',
    recorded_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    ask_again_at DATETIME NOT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
