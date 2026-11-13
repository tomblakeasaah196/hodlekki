-- /db/migrations/20261213110000_our_sermons_ai.sql
--
-- Our sermons PR S3: AI summaries from the sermon audio (includes/our_sermons/ai.php).
-- Two tables of this PR's own; the sermons table itself (our_sermons, PR S1) is not altered.
--
--   our_sermon_ai_jobs      one row per sermon the AI has been asked to draft: attempts, the
--                           last failure, the retry time, a lock while the worker runs, and a
--                           fingerprint of what the AI last wrote into each field, so a field
--                           Envision edited by hand is never overwritten.
--   our_sermon_ai_requests  one row per attempt: which sermon, who asked (NULL = the feed),
--                           the model, the audio's length and size, the tokens in and out, and
--                           whether it worked. NEVER the prompt, the audio or the answer. The
--                           day's total_tokens is the daily cost cap's meter.
--
-- Forward-only, CREATE TABLE IF NOT EXISTS, one column per line (the tests translate this
-- file to SQLite). Portable to MySQL 8 and MariaDB 10.11.

CREATE TABLE IF NOT EXISTS our_sermon_ai_jobs (
    sermon_id INT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    force_long TINYINT NOT NULL DEFAULT 0,
    queued_by INT NULL DEFAULT NULL,
    queued_at DATETIME NOT NULL,
    next_try_at DATETIME NULL DEFAULT NULL,
    locked_until DATETIME NULL DEFAULT NULL,
    failure VARCHAR(20) NULL DEFAULT NULL,
    filled TEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (sermon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS our_sermon_ai_requests (
    id INT NOT NULL AUTO_INCREMENT,
    sermon_id INT NOT NULL,
    user_id INT NULL DEFAULT NULL,
    model VARCHAR(40) NOT NULL DEFAULT '',
    audio_seconds INT NOT NULL DEFAULT 0,
    audio_bytes INT NOT NULL DEFAULT 0,
    input_tokens INT NOT NULL DEFAULT 0,
    output_tokens INT NOT NULL DEFAULT 0,
    total_tokens INT NOT NULL DEFAULT 0,
    ok TINYINT NOT NULL DEFAULT 0,
    failure VARCHAR(20) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_our_sermon_ai_requests_day (created_at),
    KEY ix_our_sermon_ai_requests_sermon (sermon_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
