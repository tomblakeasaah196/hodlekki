-- 20260926120000_reach_wipe.sql
-- Wipes the legacy Reach schema (reach_souls, reach_campaigns).
-- Prod data on these tables was one-off test fixtures; owner confirmed
-- the drop is safe. Successor tables (reach_campaigns, reach_leads,
-- reach_lead_captures, reach_campaign_fields) are created in the
-- migrations that follow this one.

DROP TABLE IF EXISTS reach_souls;
DROP TABLE IF EXISTS reach_campaigns;
