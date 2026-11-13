-- /db/migrations/20261212090000_trainings_notes.sql
--
-- Trainings: My notes (owner, 13 Nov 2026). One running note per learner and
-- training, typed in the floating notes panel on the training's page and
-- saved as they type. The learner's alone: no Academy screen, report or AI
-- reads it; they can download it as a Word file.
--
--   training_notes  UNIQUE (training_id, user_id). body is plain text, at
--                   most TRAININGS_NOTE_MAX characters (enforced in PHP).
--
-- No foreign keys, as elsewhere. Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS training_notes (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NOT NULL,
    user_id INT NOT NULL,
    body MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_notes (training_id, user_id),
    KEY ix_training_notes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
