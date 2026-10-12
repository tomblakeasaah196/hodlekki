-- 20261012090000_pwa_push.sql
-- ---------------------------------------------------------------------------
-- Installable app (PWA) + Web Push notifications.
--
-- Nothing here touches an existing table. The whole feature reads
-- `system_notifications` through a cursor (push_config.fanout_cursor_id), so
-- the ~60 places across the codebase that already INSERT a notification start
-- delivering push messages without a single call-site change.
--
-- Runs on MySQL 8.0 and MariaDB 10.11 (both are exercised by CI):
--   * no DELIMITER blocks, no generated columns, no functional indexes;
--   * endpoints are indexed by their SHA-256 hex, because a push endpoint is
--     too long for a utf8mb4 unique key;
--   * every statement is idempotent, so a half-applied deploy can be re-run.
-- ---------------------------------------------------------------------------

-- One row per browser/device a member has granted notification permission on.
-- p256dh + auth_secret are the subscriber's public key material: they let the
-- server encrypt a payload that only that browser can open. They are public
-- values by design (RFC 8291) — there is no secret of ours stored here.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED  NOT NULL,
    endpoint         TEXT          NOT NULL,
    endpoint_hash    CHAR(64)      NOT NULL,
    p256dh           VARCHAR(255)  NOT NULL,
    auth_secret      VARCHAR(64)   NOT NULL,
    content_encoding VARCHAR(20)   NOT NULL DEFAULT 'aes128gcm',
    provider         VARCHAR(40)   NOT NULL DEFAULT '',
    device_label     VARCHAR(80)   NOT NULL DEFAULT '',
    platform         VARCHAR(40)   NOT NULL DEFAULT '',
    user_agent       VARCHAR(255)  NOT NULL DEFAULT '',
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    failure_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error       VARCHAR(255)  NOT NULL DEFAULT '',
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     DATETIME      NULL DEFAULT NULL,
    last_success_at  DATETIME      NULL DEFAULT NULL,
    revoked_at       DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_push_endpoint (endpoint_hash),
    KEY idx_push_sub_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (notification, recipient). The unique key is what makes the
-- fan-out safe to re-run: a worker that dies half way cannot send anyone the
-- same notification twice. notification_id is NULL for direct sends (a test
-- push, a welcome message), and MySQL/MariaDB both allow repeated NULLs in a
-- unique index, so those are never collapsed together.
CREATE TABLE IF NOT EXISTS push_queue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED  NOT NULL,
    notification_id INT UNSIGNED  NULL DEFAULT NULL,
    source          VARCHAR(40)   NOT NULL DEFAULT 'system_notifications',
    title           VARCHAR(190)  NOT NULL,
    body            VARCHAR(500)  NOT NULL DEFAULT '',
    url             VARCHAR(255)  NOT NULL DEFAULT '/',
    tag             VARCHAR(80)   NOT NULL DEFAULT '',
    urgency         VARCHAR(10)   NOT NULL DEFAULT 'normal',
    status          VARCHAR(12)   NOT NULL DEFAULT 'pending',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    delivered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(255)  NOT NULL DEFAULT '',
    available_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_push_job (notification_id, user_id),
    KEY idx_push_queue_ready (status, available_at),
    KEY idx_push_queue_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module settings and the fan-out cursor.
--
-- The VAPID keypair lands here only when .env carries no VAPID_PUBLIC_KEY /
-- VAPID_PRIVATE_KEY, so that push works out of the box on first run. .env is
-- still the preferred home (see .env.example) — a key there survives a
-- database restore from before the feature existed, and keeps the private
-- half out of nightly SQL dumps.
--
-- `fanout_cursor_id` is NOT seeded here on purpose: includes/push_helpers.php
-- sets it to the current MAX(system_notifications.id) the first time it runs,
-- so switching the feature on never replays months of old notifications at
-- the congregation.
CREATE TABLE IF NOT EXISTS push_config (
    config_key   VARCHAR(64) NOT NULL,
    config_value TEXT        NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO push_config (config_key, config_value) VALUES
    ('enabled', '1'),
    ('inline_dispatch', '1'),
    ('subject', '')
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Per-member preferences. A member with no row here is opted in: they already
-- granted browser permission device by device, which is the real consent gate.
-- quiet_from/quiet_to are hours (0-23) in Africa/Lagos and may wrap midnight
-- (22 -> 6). Anything held back during quiet hours is still waiting in the
-- bell when they next open the app — push is an extra channel, never the only
-- one.
CREATE TABLE IF NOT EXISTS push_user_prefs (
    user_id      INT UNSIGNED NOT NULL,
    push_enabled TINYINT(1)   NOT NULL DEFAULT 1,
    quiet_from   TINYINT UNSIGNED NULL DEFAULT NULL,
    quiet_to     TINYINT UNSIGNED NULL DEFAULT NULL,
    muted_until  DATETIME     NULL DEFAULT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
