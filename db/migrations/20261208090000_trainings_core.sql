-- /db/migrations/20261208090000_trainings_core.sql
--
-- Trainings (the new HOD Academy), PR 1: the core tables. The names are a
-- shared contract (TRAININGS-PLAN.md, "Data model"): exams (PR 2) and live
-- classes (PR 3) add their own tables keyed on training_items.id.
--
--   trainings             one training: its length, dates, status, catalogue
--   training_sections     the frame the length wizard makes: days, weeks, modules
--   training_items        live / video / audio / reading / assignment / exam
--   training_enrolments   who takes it (self, assigned, invite) and how it ended
--   training_assignments  one Assign press: the audience, the due date
--   training_progress     one row per learner per item (done, submitted, returned)
--   training_invites      a visitor's one-time link (confirms the email, sets a
--                         password, opens Trainings); token stored as sha256
--
-- Person columns hold users.id. JSON lives in LONGTEXT (MySQL 8 and MariaDB
-- 10.11 alike). No foreign keys, as elsewhere in the platform: the code
-- deletes children with their parent (includes/trainings/). Forward-only,
-- safe to run twice.

CREATE TABLE IF NOT EXISTS trainings (
    id INT NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    summary TEXT NULL,
    kind VARCHAR(12) NOT NULL DEFAULT 'individual',
    official_key VARCHAR(12) NULL DEFAULT NULL,
    length VARCHAR(12) NOT NULL DEFAULT 'self_paced',
    starts_on DATE NULL DEFAULT NULL,
    ends_on DATE NULL DEFAULT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    catalogue TINYINT(1) NOT NULL DEFAULT 0,
    gives_certificate TINYINT(1) NOT NULL DEFAULT 0,
    pass_rule LONGTEXT NULL,
    department_id INT NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_trainings_status (status, catalogue),
    KEY ix_trainings_official (official_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_sections (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NOT NULL,
    kind VARCHAR(8) NOT NULL DEFAULT 'module',
    title VARCHAR(120) NOT NULL,
    date_from DATE NULL DEFAULT NULL,
    date_to DATE NULL DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_training_sections_training (training_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_items (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NOT NULL,
    section_id INT NULL DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    type VARCHAR(12) NOT NULL,
    title VARCHAR(160) NOT NULL,
    starts_at DATETIME NULL DEFAULT NULL,
    minutes INT NULL DEFAULT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    details LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_training_items_training (training_id, section_id, position),
    KEY ix_training_items_type (type, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_enrolments (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NOT NULL,
    user_id INT NOT NULL,
    source VARCHAR(8) NOT NULL DEFAULT 'self',
    assigned_by INT NULL DEFAULT NULL,
    due_on DATE NULL DEFAULT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    completed_at DATETIME NULL DEFAULT NULL,
    confirmed_by INT NULL DEFAULT NULL,
    confirmed_at DATETIME NULL DEFAULT NULL,
    certificate_no VARCHAR(40) NULL DEFAULT NULL,
    certificate_hash CHAR(64) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_enrolments (training_id, user_id),
    KEY ix_training_enrolments_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_assignments (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NOT NULL,
    audience LONGTEXT NOT NULL,
    due_days INT NULL DEFAULT NULL,
    keep_enrolling_newcomers TINYINT(1) NOT NULL DEFAULT 0,
    people INT NOT NULL DEFAULT 0,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_training_assignments_training (training_id),
    KEY ix_training_assignments_keep (keep_enrolling_newcomers)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_progress (
    id INT NOT NULL AUTO_INCREMENT,
    enrolment_id INT NOT NULL,
    item_id INT NOT NULL,
    status VARCHAR(10) NOT NULL,
    completed_at DATETIME NULL DEFAULT NULL,
    data LONGTEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_progress (enrolment_id, item_id),
    KEY ix_training_progress_item (item_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_invites (
    id INT NOT NULL AUTO_INCREMENT,
    enrolment_id INT NOT NULL,
    user_id INT NOT NULL,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_invites_hash (token_hash),
    KEY ix_training_invites_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
