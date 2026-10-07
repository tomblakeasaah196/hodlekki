-- 20261111090000_department_ministry_years.sql
-- Departments module, structural half of the ministry-year rebuild.
--
-- Three new concepts land on the existing roster table (user_departments):
--
--   membership_type  Primary / Secondary membership of a department. Moving a
--                    person between the two lists is one click in the UI.
--   ministry_year_id Which ministry year the row belongs to, so a department
--                    roster can be archived and rolled over instead of being
--                    edited in place forever.
--   roster_status    Active / Removed *within that year*. A removed row is
--                    never rendered again, but it stays in the database for
--                    audit, and re-adding the person reactivates the same row.
--
-- `is_active` keeps exactly the meaning every other module already relies on
-- (header.php, special_events, reach, charis read it): "serving right now, in
-- the live ministry year". Rolling over to a new year clears it on the old
-- year's rows, which is what keeps cross-module clearance honest while the
-- archive stays readable.
--
-- The old UNIQUE(user_id, department_id) key has to go: one person now needs
-- one row per department *per year*. It is replaced by a plain index. DROP
-- INDEX has no IF EXISTS in MySQL 8 either, so it is guarded the same way as
-- the ADD COLUMNs — information_schema check + PREPARE, no DELIMITER block.
-- Re-running any part of this file is a no-op.

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. Ministry years
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ministry_years (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label      VARCHAR(60)  NOT NULL,
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_my_active (is_active, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. New roster columns
-- ---------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'user_departments') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'user_departments'
            AND column_name = 'membership_type') = 0,
    "ALTER TABLE user_departments ADD COLUMN membership_type VARCHAR(20) NOT NULL DEFAULT 'Primary' AFTER role_in_dept",
    'DO 0'
);
PREPARE dept_my_type FROM @sql;
EXECUTE dept_my_type;
DEALLOCATE PREPARE dept_my_type;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'user_departments') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'user_departments'
            AND column_name = 'ministry_year_id') = 0,
    'ALTER TABLE user_departments ADD COLUMN ministry_year_id INT UNSIGNED NULL DEFAULT NULL AFTER season',
    'DO 0'
);
PREPARE dept_my_year FROM @sql;
EXECUTE dept_my_year;
DEALLOCATE PREPARE dept_my_year;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'user_departments') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'user_departments'
            AND column_name = 'roster_status') = 0,
    "ALTER TABLE user_departments ADD COLUMN roster_status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER ministry_year_id",
    'DO 0'
);
PREPARE dept_my_status FROM @sql;
EXECUTE dept_my_status;
DEALLOCATE PREPARE dept_my_status;

-- ---------------------------------------------------------------------------
-- 3. Drop the one-row-per-person-per-department unique key, add the indexes the
--    year-aware queries need. The index is found by its COLUMNS, not by name,
--    so the drop works whatever the key happens to be called in production.
-- ---------------------------------------------------------------------------

SET @uniq_idx := (
    SELECT s.index_name
      FROM information_schema.statistics s
     WHERE s.table_schema = @db
       AND s.table_name = 'user_departments'
       AND s.non_unique = 0
     GROUP BY s.index_name
    HAVING GROUP_CONCAT(s.column_name ORDER BY s.seq_in_index) = 'user_id,department_id'
     LIMIT 1
);

SET @sql := IF(
    @uniq_idx IS NULL OR @uniq_idx = '',
    'DO 0',
    CONCAT('ALTER TABLE user_departments DROP INDEX `', @uniq_idx, '`')
);
PREPARE dept_my_dropuniq FROM @sql;
EXECUTE dept_my_dropuniq;
DEALLOCATE PREPARE dept_my_dropuniq;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = @db AND table_name = 'user_departments'
        AND index_name = 'idx_ud_year') = 0,
    'ALTER TABLE user_departments ADD INDEX idx_ud_year (department_id, ministry_year_id, roster_status)',
    'DO 0'
);
PREPARE dept_my_idx1 FROM @sql;
EXECUTE dept_my_idx1;
DEALLOCATE PREPARE dept_my_idx1;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = @db AND table_name = 'user_departments'
        AND index_name = 'idx_ud_user_year') = 0,
    'ALTER TABLE user_departments ADD INDEX idx_ud_user_year (user_id, ministry_year_id)',
    'DO 0'
);
PREPARE dept_my_idx2 FROM @sql;
EXECUTE dept_my_idx2;
DEALLOCATE PREPARE dept_my_idx2;

-- ---------------------------------------------------------------------------
-- 4. Backfill: one bootstrap ministry year covering the current calendar year,
--    every existing roster row attached to it, rows that were already
--    soft-deleted marked Removed so the UI never shows them again.
-- ---------------------------------------------------------------------------

SET @my_year := YEAR(CURDATE());
SET @my_rows := (SELECT COUNT(*) FROM ministry_years);

INSERT INTO ministry_years (label, start_date, end_date, is_active)
SELECT CAST(@my_year AS CHAR),
       CAST(CONCAT(@my_year, '-01-01') AS DATE),
       CAST(CONCAT(@my_year, '-12-31') AS DATE),
       1
  FROM (SELECT 1) AS seed
 WHERE @my_rows = 0;

UPDATE user_departments
   SET ministry_year_id = (
        SELECT id FROM ministry_years
         WHERE is_active = 1
         ORDER BY start_date DESC, id DESC
         LIMIT 1
       )
 WHERE ministry_year_id IS NULL;

UPDATE user_departments
   SET roster_status = 'Removed'
 WHERE is_active = 0
   AND roster_status = 'Active';
