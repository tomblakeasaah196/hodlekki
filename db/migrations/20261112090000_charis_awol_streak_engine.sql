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
-- System-generated welfare notes, and an author name that outlives the account
-- --------------------------------------------------------------------------
--
-- An automatic closure has no human author, so author_id becomes nullable and
-- is_system marks the rows the engine wrote itself.
--
-- The key on author_id is restored as ON DELETE SET NULL so a welfare note
-- survives the author's account being removed -- a pastoral record must not
-- disappear because someone left. On its own that would lose WHO wrote it, so
-- author_name keeps a snapshot of the name at the time of writing and the
-- read path falls back to it. Existing rows are back-filled from users.
--
-- Nothing here hardcodes a column type. author_id is matched to whatever
-- users.id actually is, read out of information_schema: this table predates
-- the baseline and its real type (INT UNSIGNED) is not the one the
-- CREATE TABLE above declares, which is what broke the first attempt.

SET @ref_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'
);
SET @cur_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes' AND COLUMN_NAME = 'author_id'
);
SET @cur_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes' AND COLUMN_NAME = 'author_id'
);
SET @fk_name := (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
       AND COLUMN_NAME = 'author_id' AND REFERENCED_TABLE_NAME IS NOT NULL
     LIMIT 1
);
-- True while author_id is the wrong type for users.id, or still NOT NULL.
SET @needs_change := IF(@ref_type IS NOT NULL
    AND (@cur_type <> @ref_type OR @cur_nullable = 'NO'), 1, 0);

-- 1. The author-name snapshot, added first so the audit trail is in place
--    regardless of what happens to the key below.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
            AND COLUMN_NAME = 'author_name') = 0,
    'ALTER TABLE charis_welfare_notes ADD COLUMN author_name VARCHAR(161) NULL AFTER author_id',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 2. Back-fill it for every note written before the column existed.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
        AND COLUMN_NAME = 'author_name') = 1
    AND (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users') = 1,
    'UPDATE charis_welfare_notes n JOIN users u ON u.id = n.author_id
        SET n.author_name = NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''''), '' '', COALESCE(u.last_name, ''''))), '''')
      WHERE n.author_name IS NULL',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 3. Take the key off, only while the column still has to change.
SET @sql := IF(
    @fk_name IS NOT NULL AND @needs_change = 1,
    CONCAT('ALTER TABLE charis_welfare_notes DROP FOREIGN KEY `', @fk_name, '`'),
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 4. Match author_id to users.id exactly, and make it nullable.
SET @sql := IF(
    @needs_change = 1,
    CONCAT('ALTER TABLE charis_welfare_notes MODIFY COLUMN author_id ', @ref_type, ' NULL'),
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 5. Put the key back, keeping the original name where there was one.
--    Skipped once the key is present and the column already matches.
SET @sql := IF(
    @ref_type IS NOT NULL AND (@fk_name IS NULL OR @needs_change = 1),
    CONCAT('ALTER TABLE charis_welfare_notes ADD CONSTRAINT `',
           COALESCE(@fk_name, 'fk_charis_welfare_notes_author'),
           '` FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'),
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

-- 6. The flag that marks a note the engine wrote itself.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'charis_welfare_notes'
            AND COLUMN_NAME = 'is_system') = 0,
    'ALTER TABLE charis_welfare_notes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_to',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;
