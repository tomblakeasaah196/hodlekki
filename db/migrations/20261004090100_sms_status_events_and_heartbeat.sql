-- 20261004090100_sms_status_events_and_heartbeat.sql
-- Two new SMS Studio tables.
--
-- sms_status_events: one row per status movement of one sms_log message
--   (queued -> sent -> pending -> delivered / failed, plus blocked, unknown,
--   resends and manual actions). The History drawer reads it to show the full
--   timeline with the exact date and time of every step, who or what caused
--   it (queue worker, BulkSMS webhook, delivery poll, a user) and the carrier's
--   own words. Before this table only the latest status was kept.
--
-- sms_worker_heartbeat: the queue worker stamps it on every run, so the Studio
--   can tell a user that the cron job has stopped (the #1 reason a campaign
--   sits in "queued" forever) and fall back to sending from the browser.

CREATE TABLE IF NOT EXISTS sms_status_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sms_log_id     INT             NOT NULL,
    from_status    VARCHAR(20)     NULL,
    to_status      VARCHAR(20)     NOT NULL,
    source         VARCHAR(20)     NOT NULL,
    raw_status     VARCHAR(191)    NULL,
    api_code       VARCHAR(32)     NULL,
    detail         TEXT            NULL,
    provider_time  VARCHAR(40)     NULL,
    created_by     INT             NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_status_events_log (sms_log_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_worker_heartbeat (
    id            TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    last_run_at   DATETIME         NULL,
    last_cron_at  DATETIME         NULL,
    last_source   VARCHAR(10)      NULL,
    last_sent     INT              NOT NULL DEFAULT 0,
    last_failed   INT              NOT NULL DEFAULT 0,
    last_polled   INT              NOT NULL DEFAULT 0,
    last_message  VARCHAR(255)     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
