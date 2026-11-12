-- /db/migrations/20261211100000_trainings_ai.sql
--
-- Trainings PR 4b: AI help (includes/exams/ai.php, docs/EXAMS.md §7). One row
-- per Gemini call: who, what for, which training, the model, the tokens in
-- and out, and whether it worked. NEVER the prompt, the source material or
-- the answer. The day's total_tokens is the daily cost cap's meter.
-- Forward-only, CREATE TABLE IF NOT EXISTS, one column per line (the tests
-- translate this file to SQLite). Portable to MySQL 8 and MariaDB 10.11.

CREATE TABLE IF NOT EXISTS training_ai_requests (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    task VARCHAR(12) NOT NULL,
    training_id INT NULL DEFAULT NULL,
    model VARCHAR(40) NOT NULL DEFAULT '',
    input_tokens INT NOT NULL DEFAULT 0,
    output_tokens INT NOT NULL DEFAULT 0,
    total_tokens INT NOT NULL DEFAULT 0,
    ok TINYINT NOT NULL DEFAULT 0,
    failure VARCHAR(20) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_training_ai_requests_day (created_at),
    KEY ix_training_ai_requests_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
