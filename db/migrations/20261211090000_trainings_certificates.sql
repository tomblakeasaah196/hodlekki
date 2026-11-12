-- /db/migrations/20261211090000_trainings_certificates.sql
--
-- Trainings PR 4a (TRAININGS-PLAN.md, owner's answers 14 and 15):
--
--   training_certificates  every certificate ever issued: its code (CERT-XXXX-XXXX,
--                          what the QR and /verify check), its SHA-256, what was
--                          printed (snapshot), and whether it was revoked or
--                          replaced by a reissue. The CURRENT one is also on the
--                          enrolment (training_enrolments.certificate_no /
--                          certificate_hash, the plan's columns).
--   training_settings      the Academy's settings: who signs certificates (name,
--                          title, signature image). Never hard-coded.
--   training_reminders     one row per reminder sent, so nothing goes twice: a
--                          class or an exam opening the day before and an hour
--                          before, a training or an item coming due. UNIQUE on
--                          (kind, enrolment, item, the time it was about): a class
--                          moved to another time is reminded again.
--
-- No foreign keys, as elsewhere. Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS training_certificates (
    id INT NOT NULL AUTO_INCREMENT,
    enrolment_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    hash CHAR(64) NOT NULL,
    snapshot LONGTEXT NULL,
    issued_by INT NULL DEFAULT NULL,
    issued_at DATETIME NOT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    revoked_by INT NULL DEFAULT NULL,
    revoke_reason VARCHAR(200) NULL DEFAULT NULL,
    replaced_by INT NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_certificates_code (code),
    KEY ix_training_certificates_enrolment (enrolment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_settings (
    name VARCHAR(40) NOT NULL,
    value TEXT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_reminders (
    id INT NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL,
    enrolment_id INT NOT NULL,
    item_id INT NOT NULL DEFAULT 0,
    target_at DATETIME NOT NULL,
    channels VARCHAR(20) NOT NULL DEFAULT '',
    sent_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_reminders (kind, enrolment_id, item_id, target_at),
    KEY ix_training_reminders_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
