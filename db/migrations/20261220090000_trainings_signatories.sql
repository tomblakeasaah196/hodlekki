-- /db/migrations/20261220090000_trainings_signatories.sql
--
-- Trainings certificates: who signs is set per training (owner, 19 Nov 2026:
-- "We can enter the signatories for each, from the settings, when creating
-- the course"). Up to two per training, side by side on the certificate.
-- A training with none uses the Academy's signatory (training_settings).
--
--   training_signatories  position 1 or 2, the name (optional), the title and
--                         a signature image (/uploads/trainings/signature/…).
--
-- No foreign keys, as elsewhere. Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS training_signatories (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NOT NULL,
    position TINYINT NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    title VARCHAR(120) NOT NULL,
    signature_image VARCHAR(255) NOT NULL DEFAULT '',
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_signatories (training_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
