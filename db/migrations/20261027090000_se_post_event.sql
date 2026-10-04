-- 20261027090000_se_post_event.sql
-- Feedback survey and hand-off (Reach / Embrace) logs.

CREATE TABLE IF NOT EXISTS se_feedback (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    nps              TINYINT UNSIGNED NULL,
    favorite         VARCHAR(30)  NULL,
    one_word         VARCHAR(40)  NULL,
    comment          TEXT         NULL,
    wants_visit      TINYINT(1)   NOT NULL DEFAULT 0,
    future_optin     TINYINT(1)   NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_feedback (event_id, registration_id),
    CONSTRAINT fk_se_feedback_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_feedback_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_handoffs (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    reach_campaign_id  INT UNSIGNED NULL,
    summary_json       TEXT         NULL,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_handoffs_event (event_id, created_at),
    CONSTRAINT fk_se_handoffs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_handoff_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    handoff_id        INT UNSIGNED NOT NULL,
    event_id          INT UNSIGNED NOT NULL,
    registration_id   INT UNSIGNED NOT NULL,
    contact_id        INT UNSIGNED NOT NULL,
    destination       ENUM('reach','embrace','none') NOT NULL,
    outcome           ENUM('created','linked_existing','skipped_member','skipped_no_consent','skipped_no_show','skipped_opted_out','skipped_excluded','skipped_invalid_phone','already_handed_off') NOT NULL,
    target_table      VARCHAR(40)  NULL,
    target_id         INT UNSIGNED NULL,
    note              VARCHAR(160) NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    done_contact_key  INT UNSIGNED GENERATED ALWAYS AS (IF(outcome IN ('created','linked_existing'), contact_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_handoff_done (event_id, done_contact_key),
    KEY idx_se_handoff_items_handoff (handoff_id),
    CONSTRAINT fk_se_handoff_items_handoff FOREIGN KEY (handoff_id) REFERENCES se_handoffs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
