-- 20260926120300_reach_lead_captures.sql
-- One row per volunteer who captured (or re-captured) a lead. Multiple
-- volunteers can meet the same person in the same campaign; this join
-- table keeps the full history so we can show "prior capturer + date"
-- in the duplicate-warning modal without collapsing anyone.

CREATE TABLE IF NOT EXISTS reach_lead_captures (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id                   INT UNSIGNED NOT NULL,
    captured_by_user_id       INT UNSIGNED NULL,
    captured_by_guest_name    VARCHAR(150) NULL,
    captured_by_guest_phone   VARCHAR(40)  NULL,
    captured_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reach_lead_captures_lead_id (lead_id),
    KEY idx_reach_lead_captures_user (captured_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
