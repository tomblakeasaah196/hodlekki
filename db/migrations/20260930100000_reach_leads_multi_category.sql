-- 20260930100000_reach_leads_multi_category.sql
-- A person can be more than one thing at once (e.g. a broken new convert),
-- so category becomes a SET. Existing single values carry over unchanged.

ALTER TABLE reach_leads MODIFY category SET('New_Convert','Unsaved','Saved','Broken','Dechurched','Other') NOT NULL DEFAULT 'Other';
