-- 20261113090000_charis_awol_exemption.sql
-- A durable "stop monitoring this person" state for AWOL.
--
-- Until now, leaving the AWOL list was inferred from users.attendance_status.
-- That column is written by four unrelated screens (the Events profile editor,
-- the Congregation editor, Embrace and Charis itself), none of which know
-- about AWOL, so a member marked "Attends Another Church" could silently come
-- back onto the list the next time anyone edited their profile. Bro Seun
-- Musediku is the reported case.
--
-- awol_exempt is set deliberately and cleared deliberately. Nothing else
-- touches it, so once someone is off the list they stay off.
--
-- Guarded against information_schema throughout: the file runs as a single
-- PDO::exec() and the integration job applies the directory twice.

SET @db := DATABASE();

-- The flag itself.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'awol_exempt') = 0,
    'ALTER TABLE users ADD COLUMN awol_exempt TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE charis_exempt FROM @sql;
EXECUTE charis_exempt;
DEALLOCATE PREPARE charis_exempt;

-- Why they are off the list: Relocated, Attends_Another_Church, or whatever
-- final status was chosen. Kept beside the flag so the reason survives even if
-- attendance_status is later overwritten by another screen.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'awol_exempt_reason') = 0,
    'ALTER TABLE users ADD COLUMN awol_exempt_reason VARCHAR(64) NULL AFTER awol_exempt',
    'DO 0'
);
PREPARE charis_exempt FROM @sql;
EXECUTE charis_exempt;
DEALLOCATE PREPARE charis_exempt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'awol_exempt_at') = 0,
    'ALTER TABLE users ADD COLUMN awol_exempt_at DATETIME NULL AFTER awol_exempt_reason',
    'DO 0'
);
PREPARE charis_exempt FROM @sql;
EXECUTE charis_exempt;
DEALLOCATE PREPARE charis_exempt;

-- Who took them off the list. INT UNSIGNED matches users.id; deliberately no
-- foreign key, so this column never constrains a users row being removed and
-- never needs the drop/restore dance author_id required.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'awol_exempt_by') = 0,
    'ALTER TABLE users ADD COLUMN awol_exempt_by INT UNSIGNED NULL AFTER awol_exempt_at',
    'DO 0'
);
PREPARE charis_exempt FROM @sql;
EXECUTE charis_exempt;
DEALLOCATE PREPARE charis_exempt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'awol_exempt') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
            AND INDEX_NAME = 'idx_users_awol_exempt') = 0,
    'ALTER TABLE users ADD KEY idx_users_awol_exempt (awol_exempt)',
    'DO 0'
);
PREPARE charis_exempt FROM @sql;
EXECUTE charis_exempt;
DEALLOCATE PREPARE charis_exempt;

-- Carry across everyone already marked as having left, so nobody who is off
-- the list today reappears tomorrow. Unknown is deliberately NOT carried over:
-- "cannot be reached" is not "has left", and it stays excluded by status alone
-- exactly as it is today, so this migration changes nobody's visible state.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'awol_exempt_reason') = 1,
    'UPDATE users
        SET awol_exempt = 1,
            awol_exempt_reason = REPLACE(attendance_status, '' '', ''_''),
            awol_exempt_at = COALESCE(awol_exempt_at, NOW())
      WHERE awol_exempt = 0
        AND attendance_status IN (''Relocated'', ''Attends_Another_Church'', ''Attends Another Church'')',
    'DO 0'
);
PREPARE charis_exempt FROM @sql;
EXECUTE charis_exempt;
DEALLOCATE PREPARE charis_exempt;
