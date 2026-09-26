-- 20260929100100_reach_campaign_flyer.sql
-- Optional flyer image per campaign (web path under /uploads/reach_campaigns/).
-- When empty, the default image from reach_settings is used instead.

ALTER TABLE reach_campaigns ADD COLUMN flyer_path VARCHAR(255) NULL AFTER share_scripture;
