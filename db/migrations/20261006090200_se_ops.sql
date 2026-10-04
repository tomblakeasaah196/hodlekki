-- 20261006090200_se_ops.sql
-- Crew, assets, audit log, rate limits, daily metrics, AI jobs, AI usage log and SMS message runs.

CREATE TABLE IF NOT EXISTS se_crew (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        VARCHAR(20)  NOT NULL,
    added_by    INT UNSIGNED NULL,
    added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_crew (event_id, user_id, role),
    KEY idx_se_crew_user (user_id, revoked_at),
    CONSTRAINT fk_se_crew_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_assets (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NULL,
    kind           VARCHAR(20)  NOT NULL,
    role           VARCHAR(30)  NOT NULL,
    title          VARCHAR(160) NULL,
    alt_text       VARCHAR(255) NULL,
    path           VARCHAR(255) NOT NULL,
    mime           VARCHAR(60)  NOT NULL,
    bytes          INT UNSIGNED NOT NULL,
    width          SMALLINT UNSIGNED NULL,
    height         SMALLINT UNSIGNED NULL,
    duration_ms    INT UNSIGNED NULL,
    sha256         CHAR(64)     NOT NULL,
    variants_json  TEXT         NULL,
    meta_json      TEXT         NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_se_assets_event_role (event_id, role, deleted_at),
    CONSTRAINT fk_se_assets_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_audit_log (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id               INT UNSIGNED NULL,
    actor_user_id          INT UNSIGNED NULL,
    actor_registration_id  INT UNSIGNED NULL,
    action                 VARCHAR(60)  NOT NULL,
    entity                 VARCHAR(40)  NULL,
    entity_id              INT UNSIGNED NULL,
    detail_json            TEXT         NULL,
    ip_hash                CHAR(64)     NULL,
    created_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_se_audit_event (event_id, created_at),
    KEY idx_se_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_rate_limits (
    bucket        VARCHAR(40)  NOT NULL,
    subject_hash  CHAR(64)     NOT NULL,
    window_start  DATETIME     NOT NULL,
    hits          INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (bucket, subject_hash, window_start),
    KEY idx_se_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_metrics_daily (
    event_id     INT UNSIGNED NOT NULL,
    metric_date  DATE         NOT NULL,
    metric       VARCHAR(30)  NOT NULL,
    dim          VARCHAR(30)  NOT NULL DEFAULT '',
    value        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (event_id, metric_date, metric, dim),
    CONSTRAINT fk_se_metrics_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_ai_jobs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NULL,
    task             VARCHAR(40)  NOT NULL,
    input_json       MEDIUMTEXT   NULL,
    source_asset_id  INT UNSIGNED NULL,
    result_json      MEDIUMTEXT   NULL,
    status           ENUM('pending','ready','applied','discarded','failed') NOT NULL DEFAULT 'pending',
    error            VARCHAR(255) NULL,
    created_by       INT UNSIGNED NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at       DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_se_ai_jobs_event (event_id, task, created_at),
    CONSTRAINT fk_se_ai_jobs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_ai_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id          INT UNSIGNED NULL,
    event_id        INT UNSIGNED NULL,
    user_id         INT UNSIGNED NULL,
    task            VARCHAR(40)  NOT NULL,
    model           VARCHAR(60)  NOT NULL,
    prompt_version  VARCHAR(20)  NOT NULL,
    input_tokens    INT UNSIGNED NULL,
    output_tokens   INT UNSIGNED NULL,
    latency_ms      INT UNSIGNED NULL,
    http_status     SMALLINT UNSIGNED NULL,
    ok              TINYINT(1)   NOT NULL DEFAULT 0,
    error_code      VARCHAR(40)  NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_ai_req_user (user_id, created_at),
    KEY idx_se_ai_req_event (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS runs are needed from Phase A: waitlist promotions and on-demand links.
CREATE TABLE IF NOT EXISTS se_message_runs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    kind             VARCHAR(30)  NOT NULL,
    run_key          VARCHAR(80)  NOT NULL,
    scheduled_for    DATETIME     NULL,
    status           ENUM('scheduled','queued','skipped','failed') NOT NULL,
    sms_campaign_id  INT          NULL,
    recipients       INT UNSIGNED NOT NULL DEFAULT 0,
    est_units        INT UNSIGNED NOT NULL DEFAULT 0,
    detail           VARCHAR(255) NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_message_run (event_id, run_key),
    KEY idx_se_message_runs_kind (event_id, kind),
    CONSTRAINT fk_se_message_runs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
