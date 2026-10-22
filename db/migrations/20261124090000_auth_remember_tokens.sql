-- 20261124090000_auth_remember_tokens.sql
-- "Keep me signed in on this device for 30 days" (includes/auth_helpers.php).
--
-- PHP's session dies after ~24 idle minutes, so nearly every tapped
-- notification met the login screen. A device that ticked the box carries a
-- cookie "selector:validator"; this table holds the selector and only the
-- sha256 of the validator, never the validator itself. When the device has no
-- live session the token starts a new one (auth_remember_resume), after the
-- same checks the security gate makes: account status, sessions_valid_from,
-- and whether the device's row in user_sessions was revoked.
--
-- session_hash is the sha256 session id the token last backed, i.e. the
-- device's row in user_sessions, so revoking that row in the Security Centre
-- ends the device. auth_at is when that session was authenticated, compared
-- with users.sessions_valid_from. expires_at slides 30 days from last use.
--
-- No foreign keys, like user_status_history: a token must never stop a users
-- row being removed (a token whose user is gone is deleted on sight).
--
-- Plain CREATE TABLE IF NOT EXISTS so the file is safe to apply twice.

CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT NOT NULL,
    selector       CHAR(18) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    session_hash   CHAR(64) NULL,
    auth_at        DATETIME NOT NULL,
    user_agent     VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL,
    last_used_at   DATETIME NOT NULL,
    expires_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_remember_selector (selector),
    KEY idx_auth_remember_user (user_id),
    KEY idx_auth_remember_session (session_hash),
    KEY idx_auth_remember_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
