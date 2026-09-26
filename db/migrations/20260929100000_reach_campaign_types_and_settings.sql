-- 20260929100000_reach_campaign_types_and_settings.sql
-- Event types were hardcoded in the campaign form; they now live here so
-- HODs can add their own from the Reach settings button. Removing a type
-- only deactivates it, so older campaigns keep their label.
-- reach_settings holds module-wide values (e.g. the default campaign image).

CREATE TABLE IF NOT EXISTS reach_campaign_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(60)  NOT NULL,
    label       VARCHAR(100) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reach_campaign_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reach_campaign_types (code, label, sort_order) VALUES
    ('Saturday_Evangelism', 'Saturday Evangelism', 10),
    ('Crusade', 'Crusade', 20),
    ('Welfare_Outreach', 'Welfare Outreach', 30),
    ('Workshop', 'Workshop', 40),
    ('Other', 'Other', 1000)
ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS reach_settings (
    setting_key    VARCHAR(60) NOT NULL,
    setting_value  TEXT        NULL,
    updated_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
