-- 20261004090000_sms_baseline_tables.sql
-- SMS Studio has never had its schema in db/migrations/: the seven tables were
-- created by hand on the production host. This file records them so a fresh
-- install (or a host where one was never created, e.g. sms_suppression, which
-- the code treats as optional) gets every table the code reads and writes.
--
-- Every statement is CREATE TABLE IF NOT EXISTS, so on the live database —
-- where the tables already exist — this file is a no-op. Column changes to the
-- existing tables live in 20261004090200_sms_columns_and_indexes.sql.

CREATE TABLE IF NOT EXISTS sms_settings (
    skey        VARCHAR(64)  NOT NULL PRIMARY KEY,
    enc_value   TEXT         NULL,
    iv          VARCHAR(64)  NULL,
    tag         VARCHAR(64)  NULL,
    updated_by  INT          NULL,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_campaigns (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(255) NOT NULL,
    audience       VARCHAR(32)  NULL,
    event_id       INT          NULL,
    filters_json   TEXT         NULL,
    body_template  TEXT         NOT NULL,
    total          INT          NOT NULL DEFAULT 0,
    sent_count     INT          NOT NULL DEFAULT 0,
    failed_count   INT          NOT NULL DEFAULT 0,
    status         VARCHAR(20)  NOT NULL DEFAULT 'queued',
    created_by     INT          NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_campaigns_event (event_id),
    KEY idx_sms_campaigns_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_queue (
    id              INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id     INT         NOT NULL,
    recipient_json  TEXT        NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'queued',
    sent_status     VARCHAR(20) NULL,
    error_message   TEXT        NULL,
    locked_at       DATETIME    NULL,
    log_id          INT         NULL,
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_queue_status (status, id),
    KEY idx_sms_queue_campaign (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_log (
    id               INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id      INT           NULL,
    source_type      VARCHAR(32)   NULL,
    source_id        INT           NULL,
    recipient_phone  VARCHAR(32)   NOT NULL,
    recipient_name   VARCHAR(255)  NULL,
    sender_id        VARCHAR(32)   NULL,
    gateway_used     VARCHAR(64)   NULL,
    body             TEXT          NULL,
    status           VARCHAR(20)   NOT NULL DEFAULT 'queued',
    api_code         VARCHAR(32)   NULL,
    message_id       VARCHAR(128)  NULL,
    cost             DECIMAL(12,4) NULL,
    units            INT           NULL,
    error_message    TEXT          NULL,
    raw_response     TEXT          NULL,
    created_by       INT           NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NULL,
    dlr_checked_at   DATETIME      NULL,
    KEY idx_sms_log_message_id (message_id),
    KEY idx_sms_log_phone_created (recipient_phone, created_at),
    KEY idx_sms_log_campaign (campaign_id),
    KEY idx_sms_log_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_templates (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    body_template  TEXT         NOT NULL,
    created_by     INT          NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_suppression (
    id                    INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phone                 VARCHAR(32)  NOT NULL,
    reason                VARCHAR(255) NULL,
    consecutive_failures  INT          NOT NULL DEFAULT 0,
    first_failed_at       DATETIME     NULL,
    last_failed_at        DATETIME     NULL,
    suppressed_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at           DATETIME     NULL,
    released_by           INT          NULL,
    note                  TEXT         NULL,
    UNIQUE KEY uq_sms_suppression_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_webhook_log (
    id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remote_ip       VARCHAR(45)  NULL,
    headers         TEXT         NULL,
    raw_body        MEDIUMTEXT   NULL,
    message_id      VARCHAR(128) NULL,
    parsed_status   VARCHAR(191) NULL,
    applied_status  VARCHAR(20)  NULL,
    matched_rows    INT          NULL,
    KEY idx_sms_webhook_log_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
