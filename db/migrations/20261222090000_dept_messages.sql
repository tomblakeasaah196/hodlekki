-- 20261222090000_dept_messages.sql
-- Departments > Messages: one message to workers (all, chosen departments,
-- absent at an event, or leaders), sent now or at a set time, by text (SMS
-- Studio's own queue), email (the outbox, church-news rules) and app alert
-- (system_notifications, which are pushes). This table holds the message and
-- what it reached; the texts, emails and alerts live in their own tables.
-- A scheduled row is expanded once by dept_msg_tick() (the SMS worker's cron),
-- claimed with a conditional UPDATE so two runs never send it twice.

CREATE TABLE IF NOT EXISTS dept_messages (
    id               INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject          VARCHAR(150) NOT NULL,
    body             TEXT         NOT NULL,
    audience         VARCHAR(20)  NOT NULL,
    department_ids   TEXT         NULL,
    include_subunits TINYINT(1)   NOT NULL DEFAULT 1,
    event_id         INT          NULL,
    send_at          DATETIME     NOT NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'scheduled',
    sms_campaign_id  INT          NULL,
    counts_json      TEXT         NULL,
    error_message    VARCHAR(255) NULL,
    created_by       INT          NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at          DATETIME     NULL,
    KEY idx_dept_messages_due (status, send_at),
    KEY idx_dept_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
