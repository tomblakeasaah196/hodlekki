-- 20260926120400_reach_campaign_fields.sql
-- Optional-field visibility per campaign. Which optional fields are
-- shown on the public capture form is derived from payload_tier at
-- write time (Rapid vs Standard vs Rich), so on create/edit of a
-- campaign the API replaces rows here with the tier's field set.
-- field_name values in use today: address, prayer_request, age_band,
-- marital_status, language, best_time_to_call, notes.

CREATE TABLE IF NOT EXISTS reach_campaign_fields (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id  INT UNSIGNED NOT NULL,
    field_name   VARCHAR(60)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reach_campaign_fields (campaign_id, field_name),
    KEY idx_reach_campaign_fields_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
