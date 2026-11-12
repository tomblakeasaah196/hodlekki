-- /db/migrations/20261209090100_trainings_exams_review.sql
--
-- Trainings exams: the tutor's "Show answers" switch (docs/EXAMS.md §3,
-- exam_review_state()). A learner sees the right answers and explanations
-- for the questions they got wrong only once nobody can still be helped by
-- them: when the exam's window closes; with no closing time, when the
-- training's end date has passed; with neither, when a tutor shows them
-- (answers_shown_at). The tutor can also show them earlier.
-- Re-runnable (information_schema check), portable to MySQL 8 and MariaDB 10.11.

SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_exams') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_exams' AND column_name = 'answers_shown_at') = 0,
    'ALTER TABLE training_exams ADD COLUMN answers_shown_at DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE exams_s FROM @sql; EXECUTE exams_s; DEALLOCATE PREPARE exams_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_exams') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_exams' AND column_name = 'answers_shown_by') = 0,
    'ALTER TABLE training_exams ADD COLUMN answers_shown_by INT NULL DEFAULT NULL', 'DO 0');
PREPARE exams_s FROM @sql; EXECUTE exams_s; DEALLOCATE PREPARE exams_s;
