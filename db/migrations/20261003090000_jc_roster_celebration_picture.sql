-- 20261003090000_jc_roster_celebration_picture.sql
-- Junior Church children now appear in the Charis "Celebrants Overview" grid
-- alongside adult birthdays and wedding anniversaries. Adults get a 9:16
-- portrait cropped for the export via users.celebration_picture; children had
-- no equivalent, so the portrait editor had nowhere to write back to. This
-- adds the matching column to junior_church_roster.
--
-- ALTER TABLE ... ADD COLUMN has no IF NOT EXISTS in MySQL 8, so the add is
-- guarded by an information_schema check and run through PREPARE — plain
-- ;-terminated SQL, no DELIMITER block. Re-running the file is a no-op, and a
-- missing table is a no-op rather than a failed deploy.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'junior_church_roster') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'junior_church_roster'
            AND column_name = 'celebration_picture') = 0,
    'ALTER TABLE junior_church_roster ADD COLUMN celebration_picture VARCHAR(255) NULL DEFAULT NULL AFTER picture_path',
    'DO 0'
);
PREPARE jc_celeb_pic FROM @sql;
EXECUTE jc_celeb_pic;
DEALLOCATE PREPARE jc_celeb_pic;
