-- 20260926120200_reach_leads.sql
-- Fresh reach_leads table. Each row is one soul captured in the field.
-- Duplicates are detected by phone within the same campaign at write
-- time; when a phone already exists, we append a row to
-- reach_lead_captures instead of inserting a second lead. Phone and
-- campaign_id are indexed to keep that dedupe check cheap.

CREATE TABLE IF NOT EXISTS reach_leads (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id               INT UNSIGNED NULL,
    first_name                VARCHAR(100) NOT NULL,
    last_name                 VARCHAR(100) NULL,
    phone                     VARCHAR(40)  NULL,
    category                  ENUM('New_Convert','Unsaved','Saved','Broken','Dechurched','Other') NOT NULL DEFAULT 'Other',
    willing_for_visit         TINYINT(1)   NOT NULL DEFAULT 0,
    address                   VARCHAR(255) NULL,
    prayer_request            TEXT         NULL,
    age_band                  VARCHAR(40)  NULL,
    marital_status            VARCHAR(40)  NULL,
    language                  VARCHAR(60)  NULL,
    best_time_to_call         VARCHAR(60)  NULL,
    notes                     TEXT         NULL,
    status                    ENUM('Not_Spoken_To','Spoken_To','Cold','Converted','Declined') NOT NULL DEFAULT 'Not_Spoken_To',
    assigned_to               INT UNSIGNED NULL,
    assigned_at               DATETIME     NULL,
    assigned_by               INT UNSIGNED NULL,
    converted_to_user_id      INT UNSIGNED NULL,
    pushed_to_embrace_at      DATETIME     NULL,
    existing_member_user_id   INT UNSIGNED NULL,
    last_follow_up_at         DATETIME     NULL,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reach_leads_phone (phone),
    KEY idx_reach_leads_campaign_id (campaign_id),
    KEY idx_reach_leads_status (status),
    KEY idx_reach_leads_assigned_to (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
