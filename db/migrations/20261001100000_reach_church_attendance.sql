-- 20261001100000_reach_church_attendance.sql
-- "Will come to church" (they come to us) is tracked separately from
-- willing_for_visit (we go to them). Converted now means "visited church".
-- lost_alert_at throttles the daily lost-soul cron alerts per lead.

ALTER TABLE reach_leads
    ADD COLUMN will_attend_church TINYINT(1) NOT NULL DEFAULT 0 AFTER willing_for_visit,
    ADD COLUMN lost_alert_at DATETIME NULL AFTER last_follow_up_at,
    MODIFY status ENUM('Not_Spoken_To','Will_Attend','Spoken_To','Cold','Converted','Declined') NOT NULL DEFAULT 'Not_Spoken_To';

ALTER TABLE reach_follow_ups
    MODIFY outcome ENUM('Reached','Promised_Church','No_Answer','Wrong_Number','Rescheduled','Requested_No_Contact','Declined') NOT NULL;
