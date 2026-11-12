-- /db/migrations/20261209090000_trainings_exams.sql
--
-- Trainings PR 2: exams and the question bank (TRAININGS-PLAN.md; code in
-- includes/exams/, guide docs/EXAMS.md). Keyed on training_items.id (type
-- 'exam') and trainings.id from PR 1; no foreign keys, as the rest of the
-- schema. Forward-only, CREATE TABLE IF NOT EXISTS, one column per line, so
-- the tests translate this very file to SQLite. Portable to MySQL 8 and
-- MariaDB 10.11.
--
--   training_questions        the bank: one-answer, several-answers, true/false
--   training_question_imports an uploaded sheet, checked row by row, until confirmed
--   training_exams            one per exam item: window, minutes, pass mark, questions
--   training_attempts         ONE per person per exam (UNIQUE), timed on the server
--   training_attempt_answers  each question as that person saw it, and their answer
--   training_attempt_events   leaving the exam tab, coming back, submitting

-- options_json: the options in the tutor's order, e.g. ["Paul","Peter","John"]
-- (true/false: ["True","False"]). correct_json: their indexes, e.g. [0] or
-- [0,2]. import_hash: sha256 of the bank and the question's words, options
-- and answer, so importing the same sheet again never adds a question twice.
CREATE TABLE IF NOT EXISTS training_questions (
    id INT NOT NULL AUTO_INCREMENT,
    training_id INT NULL DEFAULT NULL,
    topic VARCHAR(120) NOT NULL DEFAULT '',
    qtype VARCHAR(10) NOT NULL,
    body TEXT NOT NULL,
    options_json TEXT NOT NULL,
    correct_json VARCHAR(60) NOT NULL,
    marks INT NOT NULL DEFAULT 1,
    explanation TEXT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    source VARCHAR(10) NOT NULL DEFAULT 'manual',
    import_hash CHAR(64) NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    approved_by INT NULL DEFAULT NULL,
    approved_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_questions_import (import_hash),
    KEY ix_training_questions_bank (training_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An uploaded sheet between "Check" and "Import": every row with its errors.
-- The file itself is never stored. Rows older than a day are cleared.
CREATE TABLE IF NOT EXISTS training_question_imports (
    id INT NOT NULL AUTO_INCREMENT,
    token CHAR(32) NOT NULL,
    training_id INT NULL DEFAULT NULL,
    file_name VARCHAR(190) NOT NULL DEFAULT '',
    rows_json MEDIUMTEXT NOT NULL,
    ok_rows INT NOT NULL DEFAULT 0,
    error_rows INT NOT NULL DEFAULT 0,
    duplicate_rows INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    imported_at DATETIME NULL DEFAULT NULL,
    imported INT NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_question_imports_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- question_ids_json: the exam's questions, in the tutor's order. It cannot
-- change once anyone has started. window_open / window_close (Lagos) bound
-- when an attempt may START; an attempt ends at the earlier of its own
-- minutes and window_close. leave_alert: after this many tab leaves the
-- tutor is told (once per attempt).
CREATE TABLE IF NOT EXISTS training_exams (
    id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    training_id INT NOT NULL,
    question_ids_json TEXT NOT NULL,
    window_open DATETIME NULL DEFAULT NULL,
    window_close DATETIME NULL DEFAULT NULL,
    minutes INT NOT NULL DEFAULT 30,
    pass_percent INT NOT NULL DEFAULT 50,
    shuffle TINYINT NOT NULL DEFAULT 1,
    part_marks TINYINT NOT NULL DEFAULT 0,
    leave_alert INT NOT NULL DEFAULT 3,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_exams_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ONE attempt per person per exam: the UNIQUE key, never a check-then-insert.
-- status: started | submitted. ended_by: learner | timer | closed (the window
-- closed) | tutor. Marks are written once, on the server, when it ends.
CREATE TABLE IF NOT EXISTS training_attempts (
    id INT NOT NULL AUTO_INCREMENT,
    exam_id INT NOT NULL,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    enrolment_id INT NULL DEFAULT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'started',
    started_at DATETIME NOT NULL,
    deadline_at DATETIME NOT NULL,
    submitted_at DATETIME NULL DEFAULT NULL,
    ended_by VARCHAR(10) NULL DEFAULT NULL,
    score DECIMAL(8,2) NULL DEFAULT NULL,
    max_score INT NULL DEFAULT NULL,
    percent DECIMAL(5,2) NULL DEFAULT NULL,
    passed TINYINT NULL DEFAULT NULL,
    leaves INT NOT NULL DEFAULT 0,
    tutor_told_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_attempts_once (exam_id, user_id),
    KEY ix_training_attempts_open (status, deadline_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- option_order: the order this person saw the options in, as indexes into
-- the question's options_json ("2,0,1"). The browser only ever sees the
-- shown positions; chosen_json holds the original indexes.
CREATE TABLE IF NOT EXISTS training_attempt_answers (
    id INT NOT NULL AUTO_INCREMENT,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    position INT NOT NULL,
    option_order VARCHAR(40) NOT NULL,
    chosen_json VARCHAR(60) NULL DEFAULT NULL,
    answered_at DATETIME NULL DEFAULT NULL,
    score DECIMAL(6,2) NULL DEFAULT NULL,
    is_correct TINYINT NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_attempt_answers (attempt_id, question_id),
    KEY ix_training_attempt_answers_position (attempt_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: start | leave | return | submit | timeout | told (the tutor was told).
CREATE TABLE IF NOT EXISTS training_attempt_events (
    id INT NOT NULL AUTO_INCREMENT,
    attempt_id INT NOT NULL,
    kind VARCHAR(10) NOT NULL,
    detail VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_training_attempt_events_attempt (attempt_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
