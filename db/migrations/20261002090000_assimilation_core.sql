-- 20261002090000_assimilation_core.sql
-- Assimilation: bringing people who have drifted away from church back home.
--
-- assimilation_team        who may work the module as a volunteer (anyone in
--                         the congregation, not only workers). Removal is a
--                         deactivation so the follow-up history stays readable.
-- assimilation_watchlists  a saved "drifted" rule (rule_json) with a live count.
-- assimilation_watchlist_hits  first_seen_at per person per watchlist, so the
--                         daily cron announces someone once, not every morning.
-- assimilation_cases      one row per attempt to bring a person home. At most
--                         one OPEN case per person, enforced by the unique key
--                         on the generated open_user_id column (NULL once the
--                         case closes, and unique keys ignore NULLs).
-- assimilation_follow_ups every call, message and visit. notes is what the
--                         volunteer wrote, notes_clean the AI tidy-up.
-- assimilation_case_assignments  audit trail of assign / reassign / self_claim
--                         / unassign, like reach_lead_assignments.
-- assimilation_settings   module-wide key/value settings, like reach_settings.

CREATE TABLE IF NOT EXISTS assimilation_team (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    added_by   INT UNSIGNED NULL,
    added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    notes      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_assim_team_user (user_id),
    KEY idx_assim_team_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assimilation_watchlists (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    rule_json   TEXT         NOT NULL,
    created_by  INT UNSIGNED NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    notify      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assim_watchlists_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assimilation_watchlist_hits (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    watchlist_id   INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    announced_at   DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_assim_hit (watchlist_id, user_id),
    KEY idx_assim_hit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assimilation_cases (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    watchlist_id      INT UNSIGNED NULL,
    assigned_to       INT UNSIGNED NULL,
    assigned_by       INT UNSIGNED NULL,
    assigned_at       DATETIME     NULL,
    status            ENUM('To_Call','Reached','Promised','Returned_Home','Unreachable','Not_Interested','Relocated','Attends_Elsewhere') NOT NULL DEFAULT 'To_Call',
    opened_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at         DATETIME     NULL,
    closed_by         INT UNSIGNED NULL,
    outcome           VARCHAR(255) NULL,
    returned_home_at  DATETIME     NULL,
    first_contact_at  DATETIME     NULL,
    last_contact_at   DATETIME     NULL,
    next_touch_date   DATE         NULL,
    last_attended_on  DATE         NULL,
    open_user_id      INT UNSIGNED GENERATED ALWAYS AS (IF(closed_at IS NULL, user_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_assim_one_open_case (open_user_id),
    KEY idx_assim_cases_user (user_id),
    KEY idx_assim_cases_assigned_to (assigned_to),
    KEY idx_assim_cases_status (status),
    KEY idx_assim_cases_next_touch (next_touch_date),
    KEY idx_assim_cases_watchlist (watchlist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assimilation_follow_ups (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id          INT UNSIGNED NOT NULL,
    logged_by        INT UNSIGNED NULL,
    channel          ENUM('Call','WhatsApp','SMS','Visit','At_Church') NOT NULL,
    outcome          ENUM('Spoke_With_Them','Promised_To_Come','No_Answer','Wrong_Number','Not_Interested','Relocated','Attends_Elsewhere','Asked_For_No_Contact') NOT NULL,
    notes            TEXT         NULL,
    notes_clean      TEXT         NULL,
    prayer_points    TEXT         NULL,
    next_touch_date  DATE         NULL,
    created_by_phone VARCHAR(40)  NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assim_follow_ups_case (case_id),
    KEY idx_assim_follow_ups_created (created_at),
    KEY idx_assim_follow_ups_by (logged_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assimilation_case_assignments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id       INT UNSIGNED NOT NULL,
    from_user_id  INT UNSIGNED NULL,
    to_user_id    INT UNSIGNED NULL,
    assigned_by   INT UNSIGNED NULL,
    action        ENUM('assign','reassign','self_claim','unassign') NOT NULL,
    notes         VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assim_assignments_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assimilation_settings (
    setting_key    VARCHAR(60) NOT NULL,
    setting_value  TEXT        NULL,
    updated_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO assimilation_settings (setting_key, setting_value) VALUES
    ('overdue_days', '7'),
    ('allow_self_claim', '1'),
    ('default_rule', '{"max_services":3,"window_value":2,"window_unit":"months","ever_attended":1}')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
