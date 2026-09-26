-- 20260927100100_reach_lead_assignments.sql
-- Audit trail of who a Reach lead was assigned to, by whom, and how.
-- reach_leads.assigned_to holds the current assignee; this keeps history.

CREATE TABLE IF NOT EXISTS reach_lead_assignments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id       INT UNSIGNED NOT NULL,
    from_user_id  INT UNSIGNED NULL,
    to_user_id    INT UNSIGNED NULL,
    assigned_by   INT UNSIGNED NOT NULL,
    action        ENUM('assign','self_claim','reassign','unassign') NOT NULL,
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reach_lead_assignments_lead_id (lead_id),
    KEY idx_reach_lead_assignments_to_user (to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
