-- 20260928100000_reach_report_flags.sql
-- Opt-in flag: a converted lead's story may appear in the Testimonies
-- section of the monthly Reach PDF only when this is set.

ALTER TABLE reach_leads ADD COLUMN share_testimony_in_report TINYINT(1) NOT NULL DEFAULT 0;
