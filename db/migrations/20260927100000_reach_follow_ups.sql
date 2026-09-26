-- 20260927100000_reach_follow_ups.sql
-- One row per follow-up attempt on a Reach lead. reach_leads.status is
-- recomputed from these rows by api/reach_api.php (log_follow_up).

CREATE TABLE IF NOT EXISTS reach_follow_ups (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id          INT UNSIGNED NOT NULL,
    followed_up_by   INT UNSIGNED NULL,
    channel          ENUM('Call','WhatsApp','SMS','In_Person_Visit','Church_Service') NOT NULL,
    outcome          ENUM('Reached','No_Answer','Wrong_Number','Rescheduled','Requested_No_Contact','Declined') NOT NULL,
    notes            TEXT NULL,
    next_touch_date  DATE NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reach_follow_ups_lead_id (lead_id),
    KEY idx_reach_follow_ups_next_touch (next_touch_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
