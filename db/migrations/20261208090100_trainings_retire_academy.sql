-- /db/migrations/20261208090100_trainings_retire_academy.sql
--
-- The old HOD Academy retires (owner's answer 16, 12 Nov 2026: it was never
-- used, everything in it was test data). Its tables are RENAMED with a
-- _retired_20261112 suffix, never dropped, so nothing is lost; the lead drops
-- them later with the owner's confirmation. Trainings (20261208090000) starts
-- clean. course_materials and user_exam_answers belonged to the old Academy
-- too (its API was their only reader and writer), so they retire with it.
-- Foreign keys between these tables follow the rename; events.academy_batch_id
-- keeps pointing at the retired batches table and is not touched here.
--
-- RENAME TABLE has no IF EXISTS, so each one runs through PREPARE after an
-- information_schema check (the repo's idempotent-DDL pattern, as in
-- 20261103090000): a table that is missing (a fresh database) or already
-- retired (a second run) is a no-op. Plain ;-terminated SQL, no DELIMITER.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_applications') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_applications_retired_20261112') = 0,
    'RENAME TABLE academy_applications TO academy_applications_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_assignments') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_assignments_retired_20261112') = 0,
    'RENAME TABLE academy_assignments TO academy_assignments_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_batches') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_batches_retired_20261112') = 0,
    'RENAME TABLE academy_batches TO academy_batches_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_courses') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_courses_retired_20261112') = 0,
    'RENAME TABLE academy_courses TO academy_courses_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_enrollments') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_enrollments_retired_20261112') = 0,
    'RENAME TABLE academy_enrollments TO academy_enrollments_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_forum_messages') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_forum_messages_retired_20261112') = 0,
    'RENAME TABLE academy_forum_messages TO academy_forum_messages_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_group_members') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_group_members_retired_20261112') = 0,
    'RENAME TABLE academy_group_members TO academy_group_members_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_interview_scores') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_interview_scores_retired_20261112') = 0,
    'RENAME TABLE academy_interview_scores TO academy_interview_scores_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_programs') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_programs_retired_20261112') = 0,
    'RENAME TABLE academy_programs TO academy_programs_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_student_groups') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_student_groups_retired_20261112') = 0,
    'RENAME TABLE academy_student_groups TO academy_student_groups_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_submissions') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'academy_submissions_retired_20261112') = 0,
    'RENAME TABLE academy_submissions TO academy_submissions_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'course_materials') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'course_materials_retired_20261112') = 0,
    'RENAME TABLE course_materials TO course_materials_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'exam_questions') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'exam_questions_retired_20261112') = 0,
    'RENAME TABLE exam_questions TO exam_questions_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_exams') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_exams_retired_20261112') = 0,
    'RENAME TABLE user_exams TO user_exams_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_exam_answers') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_exam_answers_retired_20261112') = 0,
    'RENAME TABLE user_exam_answers TO user_exam_answers_retired_20261112',
    'DO 0'
);
PREPARE trainings_retire FROM @sql;
EXECUTE trainings_retire;
DEALLOCATE PREPARE trainings_retire;
