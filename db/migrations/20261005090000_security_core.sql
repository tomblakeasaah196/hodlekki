-- 20261005090000_security_core.sql
-- Account security core: the schema behind "My Profile → Security" (self-service
-- password change) and the admin Security Centre (modules/security).
--
-- 1. users gets the account lifecycle + credential columns the login gate now
--    enforces:
--      account_status        active | suspended | revoked. Anything other than
--                            'active' blocks authentication outright.
--      status_reason         free text shown to admins (and logged).
--      status_changed_at/by  who flipped the switch and when.
--      must_change_password  1 forces /auth/change_password.php before the user
--                            can reach any other authenticated page.
--      password_changed_at   drives the "password age" readout.
--      sessions_valid_from   blunt session kill-switch. Any session created
--                            before this timestamp is dead, including sessions
--                            that predate the user_sessions table.
--      last_login_at/ip      last successful authentication.
--      failed_login_count    consecutive failures; reset on success.
--      locked_until          set when failed_login_count trips the threshold.
--
-- 2. Three new tables:
--      security_audit_log  every privileged security action (who did what to
--                          whom, from which IP).
--      user_sessions       one row per browser session, so an admin can see who
--                          is signed in and revoke a single device or all of
--                          them. Keyed by sha256(session_id) — the raw PHP
--                          session id is never stored.
--      login_attempts      success and failure history, feeding both the
--                          lockout logic and the Login Activity tab.
--
-- 3. user_roles.is_frozen is created if the column is somehow missing, because
--    revoking an account now freezes every role row (the account comes back
--    with zero clearance until an admin restores it).
--
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS, so each ALTER is guarded by an
-- information_schema check and run through PREPARE (plain ;-terminated SQL, no
-- DELIMITER blocks). Re-running the file is a no-op.

SET @db := DATABASE();

-- ---------------------------------------------------------------- 1. users columns

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'account_status') = 0,
    'ALTER TABLE users ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT ''active''',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'status_reason') = 0,
    'ALTER TABLE users ADD COLUMN status_reason VARCHAR(255) NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'status_changed_at') = 0,
    'ALTER TABLE users ADD COLUMN status_changed_at DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'status_changed_by') = 0,
    'ALTER TABLE users ADD COLUMN status_changed_by INT NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'must_change_password') = 0,
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'password_changed_at') = 0,
    'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'sessions_valid_from') = 0,
    'ALTER TABLE users ADD COLUMN sessions_valid_from DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'last_login_at') = 0,
    'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'last_login_ip') = 0,
    'ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'failed_login_count') = 0,
    'ALTER TABLE users ADD COLUMN failed_login_count INT NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'locked_until') = 0,
    'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

-- Index the status column so the Security Centre roster filter stays cheap.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'account_status') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'users'
            AND column_name = 'account_status' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_users_account_status ON users (account_status)',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

-- ---------------------------------------------------------------- 2. user_roles.is_frozen safety net

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'user_roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'is_frozen') = 0,
    'ALTER TABLE user_roles ADD COLUMN is_frozen TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE sec_mig FROM @sql;
EXECUTE sec_mig;
DEALLOCATE PREPARE sec_mig;

-- ---------------------------------------------------------------- 3. new tables

CREATE TABLE IF NOT EXISTS security_audit_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id  INT          NULL,
    actor_label    VARCHAR(150) NULL,
    target_user_id INT          NULL,
    action         VARCHAR(60)  NOT NULL,
    details        TEXT         NULL,
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sec_audit_created (created_at),
    INDEX idx_sec_audit_target (target_user_id, created_at),
    INDEX idx_sec_audit_actor (actor_user_id, created_at),
    INDEX idx_sec_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    session_hash  CHAR(64)     NOT NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at    DATETIME     NULL,
    revoked_by    INT          NULL,
    revoke_reason VARCHAR(160) NULL,
    UNIQUE KEY uniq_user_sessions_hash (session_hash),
    INDEX idx_user_sessions_user (user_id, revoked_at),
    INDEX idx_user_sessions_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT          NULL,
    email          VARCHAR(190) NULL,
    was_successful TINYINT(1)   NOT NULL DEFAULT 0,
    failure_reason VARCHAR(80)  NULL,
    context        VARCHAR(40)  NOT NULL DEFAULT 'login',
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_created (created_at),
    INDEX idx_login_attempts_user (user_id, created_at),
    INDEX idx_login_attempts_email (email, created_at),
    INDEX idx_login_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- 4. backfill

-- Existing rows predate the column default in some MySQL configurations.
UPDATE users SET account_status = 'active'
 WHERE account_status IS NULL OR account_status = '';
