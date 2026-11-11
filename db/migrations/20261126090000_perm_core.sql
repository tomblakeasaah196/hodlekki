-- 20261126090000_perm_core.sql
-- Roles & permissions, part 1 of 3: the tables the permission matrix lives in.
--
-- Nothing reads these tables until the gates are switched over (PR 2), so this
-- file changes no one's access. The matrix itself is seeded by
-- 20261126090200_perm_day_one.sql; columns on existing tables come from
-- 20261126090100_perm_platform_columns.sql.
--
--   perm_seat_types        the seats a department can have (Worker, HOD, ...).
--                          seat_key is the STABLE key other code and data refer
--                          to; roster_value is what user_departments.role_in_dept
--                          stores; the label is free text.
--   perm_department_seats  which seat types each department uses, and how many
--                          people may hold one (per-department override).
--   perm_module_owners     which departments own a module. department_id 0 means
--                          "every department".
--   perm_grants            one row per matrix cell that is not "none". Exactly one
--                          holder per row: a role (role_id > 0, seat_key ''), a
--                          seat type (role_id 0, seat_key = the key) or everyone
--                          signed in (role_id 0, seat_key '@everyone'). No NULLs
--                          in the key on purpose: MySQL treats NULLs as distinct
--                          in a unique key, which would allow duplicate cells.
--   perm_history           who changed what, when, before and after — for every
--                          role, seat and permission change.
--   perm_config            switches, e.g. expiry_enforced.
--
-- Written one column per line, ending in ENGINE=, so tests/permissions can
-- translate this very file to SQLite. Re-running it is a no-op.

CREATE TABLE IF NOT EXISTS perm_seat_types (
    seat_key VARCHAR(30) NOT NULL,
    roster_value VARCHAR(30) NOT NULL,
    label VARCHAR(60) NOT NULL,
    seat_rank SMALLINT NOT NULL DEFAULT 0,
    is_leader TINYINT(1) NOT NULL DEFAULT 0,
    covers_sub_units TINYINT(1) NOT NULL DEFAULT 0,
    max_holders SMALLINT NULL DEFAULT NULL,
    can_appoint TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (seat_key),
    UNIQUE KEY uniq_perm_seat_roster_value (roster_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perm_department_seats (
    department_id INT NOT NULL,
    seat_key VARCHAR(30) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    max_holders SMALLINT NULL DEFAULT NULL,
    PRIMARY KEY (department_id, seat_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perm_module_owners (
    module_key VARCHAR(40) NOT NULL,
    department_id INT NOT NULL,
    PRIMARY KEY (module_key, department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perm_grants (
    role_id INT NOT NULL DEFAULT 0,
    seat_key VARCHAR(30) NOT NULL DEFAULT '',
    module_key VARCHAR(40) NOT NULL,
    level TINYINT NOT NULL DEFAULT 0,
    can_approve TINYINT(1) NOT NULL DEFAULT 0,
    can_export TINYINT(1) NOT NULL DEFAULT 0,
    can_manage TINYINT(1) NOT NULL DEFAULT 0,
    scope VARCHAR(10) NOT NULL DEFAULT 'church',
    view_scope VARCHAR(10) NULL DEFAULT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (role_id, seat_key, module_key),
    KEY idx_perm_grants_seat (seat_key, module_key),
    KEY idx_perm_grants_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perm_history (
    id INT NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_user_id INT NULL DEFAULT NULL,
    actor_label VARCHAR(150) NULL DEFAULT NULL,
    via VARCHAR(20) NOT NULL,
    change_type VARCHAR(40) NOT NULL,
    subject_user_id INT NULL DEFAULT NULL,
    role_id INT NULL DEFAULT NULL,
    department_id INT NULL DEFAULT NULL,
    seat_key VARCHAR(30) NULL DEFAULT NULL,
    module_key VARCHAR(40) NULL DEFAULT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    reason VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_perm_history_created (created_at),
    KEY idx_perm_history_subject (subject_user_id, created_at),
    KEY idx_perm_history_role (role_id, created_at),
    KEY idx_perm_history_module (module_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perm_config (
    cfg_key VARCHAR(40) NOT NULL,
    cfg_value VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (cfg_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
