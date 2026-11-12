-- /db/migrations/20261209090200_trainings_exams_retakes.sql
--
-- Trainings exams: retakes, set per quiz (owner, 12 Nov 2026; docs/EXAMS.md §3).
--   training_exams.attempts_allowed  1, 2 or 3 (default 1: every quiz so far stays one attempt).
--   training_attempts.attempt_no     1, 2, 3 for one person on one exam; the rows already there become 1.
-- The one-attempt key UNIQUE (exam_id, user_id) becomes UNIQUE (exam_id, user_id, attempt_no):
-- one row per attempt number, so two tabs starting the same attempt still share
-- one (the insert decides, never a check first). The new key is added before
-- the old one is dropped, so there is no moment without one.
-- Re-runnable (information_schema checks), portable to MySQL 8 and MariaDB 10.11.

SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_exams') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_exams' AND column_name = 'attempts_allowed') = 0,
    'ALTER TABLE training_exams ADD COLUMN attempts_allowed TINYINT NOT NULL DEFAULT 1', 'DO 0');
PREPARE exams_s FROM @sql; EXECUTE exams_s; DEALLOCATE PREPARE exams_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_attempts') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_attempts' AND column_name = 'attempt_no') = 0,
    'ALTER TABLE training_attempts ADD COLUMN attempt_no INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE exams_s FROM @sql; EXECUTE exams_s; DEALLOCATE PREPARE exams_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_attempts' AND column_name = 'attempt_no') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @db AND table_name = 'training_attempts' AND index_name = 'uq_training_attempts_no') = 0,
    'ALTER TABLE training_attempts ADD UNIQUE KEY uq_training_attempts_no (exam_id, user_id, attempt_no)', 'DO 0');
PREPARE exams_s FROM @sql; EXECUTE exams_s; DEALLOCATE PREPARE exams_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @db AND table_name = 'training_attempts' AND index_name = 'uq_training_attempts_no') > 0
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @db AND table_name = 'training_attempts' AND index_name = 'uq_training_attempts_once') > 0,
    'ALTER TABLE training_attempts DROP INDEX uq_training_attempts_once', 'DO 0');
PREPARE exams_s FROM @sql; EXECUTE exams_s; DEALLOCATE PREPARE exams_s;
