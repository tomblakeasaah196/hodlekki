-- 20261112090000_charis_awol_streak_engine.sql
-- Rebuilds AWOL Monitoring around a consecutive-absence streak.
--
-- The previous engine counted total misses inside a rolling window, so an
-- absence BEFORE a member's last attendance still counted against them: a
-- member present on 4 Oct could still read "3 MISSED". The streak engine
-- counts only unbroken absences since the member was last in the house, and
-- any presence resets it to zero.
--
-- Forward-only. Legacy columns (services_missed, missed_threshold,
-- period_weeks, service_types) are LEFT IN PLACE so a half-deployed tree
-- keeps reading rows it understands.
--
-- Every statement is guarded against information_schema the same way
-- 20261005093000_charis_welfare_integrity.sql does: the whole file runs as a
-- single PDO::exec(), and the Special Events integration job applies the
-- directory twice to prove idempotency, so a bare ADD COLUMN would fail its
-- second pass.

SET @db := DATABASE();

-- --------------------------------------------------------------------------
-- charis_awol_config: consecutive threshold, archive horizon, service grid
-- --------------------------------------------------------------------------

-- Consecutive Sunday absences required before a member is flagged.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_awol_config') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_awol_config'
            AND column_name = 'consecutive_threshold') = 0,
    'ALTER TABLE charis_awol_config ADD COLUMN consecutive_threshold INT UNSIGNED NOT NULL DEFAULT 3 AFTER missed_threshold',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- Unbroken weeks away before a member leaves the active list for Dormant.
-- Deliberately NOT seeded from period_weeks: that column means "rolling focus
-- window" (live value 3), and reusing it would archive everyone after three
-- weeks. 12 weeks is the new, independent default.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_awol_config') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_awol_config'
            AND column_name = 'archive_weeks') = 0,
    'ALTER TABLE charis_awol_config ADD COLUMN archive_weeks INT UNSIGNED NOT NULL DEFAULT 12 AFTER period_weeks',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- Per-service behaviour:
--   {"Sunday_Service":{"counts_absence":true,"resets_clock":true},
--    "Midweek_Service":{"counts_absence":false,"resets_clock":true}}
-- Midweek absence must never flag anyone (members travel far and miss midweek
-- for work), but midweek presence still proves they are engaged and resets.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_awol_config') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_awol_config'
            AND column_name = 'service_rules') = 0,
    'ALTER TABLE charis_awol_config ADD COLUMN service_rules JSON NULL AFTER service_types',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- --------------------------------------------------------------------------
-- charis_awol_config_history: same three columns, for the audit trail
-- --------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_awol_config_history') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_awol_config_history'
            AND column_name = 'consecutive_threshold') = 0,
    'ALTER TABLE charis_awol_config_history ADD COLUMN consecutive_threshold INT UNSIGNED NOT NULL DEFAULT 3 AFTER missed_threshold',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_awol_config_history') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_awol_config_history'
            AND column_name = 'archive_weeks') = 0,
    'ALTER TABLE charis_awol_config_history ADD COLUMN archive_weeks INT UNSIGNED NOT NULL DEFAULT 12 AFTER period_weeks',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_awol_config_history') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_awol_config_history'
            AND column_name = 'service_rules') = 0,
    'ALTER TABLE charis_awol_config_history ADD COLUMN service_rules JSON NULL AFTER service_types',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- --------------------------------------------------------------------------
-- Carry the live rule across so nothing silently changes on deploy
-- --------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'charis_awol_config'
        AND column_name = 'consecutive_threshold') = 1,
    'UPDATE charis_awol_config SET consecutive_threshold = GREATEST(1, missed_threshold) WHERE id = 1 AND consecutive_threshold = 3',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- Default asymmetry for any row that predates service_rules.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'charis_awol_config'
        AND column_name = 'service_rules') = 1,
    'UPDATE charis_awol_config SET service_rules = ''{"Sunday_Service":{"counts_absence":true,"resets_clock":true},"Midweek_Service":{"counts_absence":false,"resets_clock":true}}'' WHERE id = 1 AND service_rules IS NULL',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- --------------------------------------------------------------------------
-- Sundays that did not hold
-- --------------------------------------------------------------------------
--
-- A Sunday with zero attendance church-wide is treated as not held and
-- SKIPPED: it never advances a streak, and never resets one. Rows are written
-- by the engine so a broken check-in scanner stays visible instead of
-- silently pausing everybody's counter.

CREATE TABLE IF NOT EXISTS `charis_awol_skipped_services` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_date` DATE NOT NULL,
    `event_category` VARCHAR(64) NOT NULL,
    `reason` VARCHAR(64) NOT NULL DEFAULT 'zero_attendance',
    `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_charis_skipped_service` (`service_date`, `event_category`),
    KEY `idx_charis_skipped_detected` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Automatic case closure audit
-- --------------------------------------------------------------------------
--
-- When IDI marks someone present, their AWOL case and any manual flag close
-- themselves and the system writes the archive note -- no worker action, full
-- trace.

CREATE TABLE IF NOT EXISTS `charis_awol_auto_resolutions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_user_id` BIGINT UNSIGNED NOT NULL,
    `assignment_id` BIGINT UNSIGNED NULL,
    `followup_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `case_type` VARCHAR(32) NOT NULL DEFAULT 'AWOL',
    `attended_on` DATE NOT NULL,
    `event_category` VARCHAR(64) NULL,
    `streak_at_resolution` INT UNSIGNED NOT NULL DEFAULT 0,
    `note_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_charis_auto_resolution` (`target_user_id`, `followup_id`, `attended_on`),
    KEY `idx_charis_auto_res_user` (`target_user_id`),
    KEY `idx_charis_auto_res_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- System-generated welfare notes
-- --------------------------------------------------------------------------
--
-- An automatic closure has no human author. Attributing one to the assigned
-- worker would be a lie in the audit trail, so author_id becomes nullable and
-- is_system marks the rows the engine wrote itself.

-- Production carries a foreign key on author_id (charis_welfare_notes_ibfk_2)
-- that no migration in this repo creates -- the table predates the baseline.
-- MySQL refuses to make a column nullable while an FK uses it (error 1832),
-- so the key has to come off, the column change, and the key go back on.
--
-- It is rebuilt with the referenced table, column and rules read back from
-- information_schema rather than assumed, so whatever production actually has
-- survives this migration unchanged. On an installation with no such FK (the
-- integration test database) every FK step is skipped and only the column
-- change runs.

SET @needs_null := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND COLUMN_NAME = 'author_id' AND IS_NULLABLE = 'NO'
);

SET @fk_name := (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND COLUMN_NAME = 'author_id' AND REFERENCED_TABLE_NAME IS NOT NULL
     LIMIT 1
);
SET @fk_ref_table := (
    SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND COLUMN_NAME = 'author_id' AND REFERENCED_TABLE_NAME IS NOT NULL
     LIMIT 1
);
SET @fk_ref_col := (
    SELECT REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND COLUMN_NAME = 'author_id' AND REFERENCED_TABLE_NAME IS NOT NULL
     LIMIT 1
);
SET @fk_del := (
    SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND CONSTRAINT_NAME = @fk_name
);
SET @fk_upd := (
    SELECT UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND CONSTRAINT_NAME = @fk_name
);

-- 1. Drop the key, only while the column still needs changing.
SET @sql := IF(
    @needs_null = 1 AND @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE charis_welfare_notes DROP FOREIGN KEY `', @fk_name, '`'),
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 2. Make author_id nullable: a system-written note has no human author.
SET @sql := IF(
    @needs_null = 1,
    'ALTER TABLE charis_welfare_notes MODIFY COLUMN author_id BIGINT UNSIGNED NULL',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 3. Put the key back exactly as it was found.
SET @sql := IF(
    @needs_null = 1 AND @fk_name IS NOT NULL AND @fk_ref_table IS NOT NULL,
    CONCAT('ALTER TABLE charis_welfare_notes ADD CONSTRAINT `', @fk_name,
           '` FOREIGN KEY (author_id) REFERENCES `', @fk_ref_table, '`(`', @fk_ref_col, '`)',
           ' ON DELETE ', COALESCE(@fk_del, 'CASCADE'),
           ' ON UPDATE ', COALESCE(@fk_upd, 'NO ACTION')),
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_welfare_notes') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_welfare_notes'
            AND column_name = 'is_system') = 0,
    'ALTER TABLE charis_welfare_notes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_to',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;
