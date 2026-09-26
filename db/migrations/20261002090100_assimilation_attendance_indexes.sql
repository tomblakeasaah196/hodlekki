-- 20261002090100_assimilation_attendance_indexes.sql
-- The Assimilation "who has drifted" query reads every attendance day for
-- every person, as the union of `checkins` and `attendance` (see
-- assim_attendance_union_sql() in includes/assimilation_helpers.php). These
-- two indexes make that union an index-only scan and keep the per-person
-- attendance timeline in the case drawer cheap.
--
-- CREATE INDEX has no IF NOT EXISTS in MySQL 8, so each one is guarded by an
-- information_schema check and run through PREPARE — plain ;-terminated SQL,
-- no DELIMITER block. Re-running the file is a no-op. A missing table is also
-- a no-op rather than a failed deploy.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'checkins') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'checkins'
            AND index_name = 'idx_checkins_user_date') = 0,
    'CREATE INDEX idx_checkins_user_date ON checkins (user_id, checkin_date)',
    'DO 0'
);
PREPARE assim_idx FROM @sql;
EXECUTE assim_idx;
DEALLOCATE PREPARE assim_idx;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'attendance') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'attendance'
            AND index_name = 'idx_attendance_user_date') = 0,
    'CREATE INDEX idx_attendance_user_date ON attendance (user_id, attendance_date)',
    'DO 0'
);
PREPARE assim_idx FROM @sql;
EXECUTE assim_idx;
DEALLOCATE PREPARE assim_idx;
