-- 20261027090100_se_reach_campaign_type.sql
-- Reach campaign type used by the Special Events hand-off.

INSERT INTO reach_campaign_types (code, label, sort_order) VALUES
    ('Special_Event', 'Special Event', 50)
ON DUPLICATE KEY UPDATE code = code;
