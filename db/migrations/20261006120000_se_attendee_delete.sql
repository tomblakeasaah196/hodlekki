-- /db/migrations/20261006120000_se_attendee_delete.sql
--
-- Studio → Attendees: a real "Delete" (§12.5).
--
-- `status` already carries three of the four answers and none of them can be
-- the fourth. 'cancelled' belongs to "Release seat" and to the portal's own
-- self-cancel; 'removed' carries the block that makes the portal refuse a
-- return (`outcome: 'blocked'`, §12.2). The crew needs a delete that takes
-- someone off the list AND frees the number, so that the same person can
-- register again — which is neither of those.
--
-- So the marker lives in its own column, and `status` keeps its meaning:
-- a deleted registration is 'cancelled' (or 'removed', when the crew also
-- asked to block the number) and `deleted_at` says why it is really gone.
--
-- MySQL 8 has no ADD COLUMN / CREATE INDEX IF NOT EXISTS, so — exactly as
-- 20261004090200_sms_columns_and_indexes.sql does — every change is guarded by
-- an information_schema check and run through PREPARE (plain ;-terminated SQL,
-- no DELIMITER). Re-running is a no-op. That matters beyond production: the
-- integration harness applies every file twice to prove idempotency.

SET @db := DATABASE();

-- ------------------------------------------------------------- 1. the marker

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'se_registrations') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'se_registrations'
            AND column_name = 'deleted_at') = 0,
    'ALTER TABLE se_registrations ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE se_del_mig FROM @sql;
EXECUTE se_del_mig;
DEALLOCATE PREPARE se_del_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'se_registrations') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'se_registrations'
            AND column_name = 'deleted_by') = 0,
    'ALTER TABLE se_registrations ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL',
    'DO 0'
);
PREPARE se_del_mig FROM @sql;
EXECUTE se_del_mig;
DEALLOCATE PREPARE se_del_mig;

-- -------------------------------------------------------------- 2. the index

-- Guarded on the index NAME, not on its leading column: this index starts
-- with `event_id`, so a "does any index start with deleted_at" test would not
-- see it and would try to create it a second time. The column guard is
-- repeated because an index cannot be built before its column exists.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'se_registrations'
        AND column_name = 'deleted_at' AND data_type = 'datetime') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'se_registrations'
            AND index_name = 'idx_se_reg_deleted') = 0,
    'CREATE INDEX idx_se_reg_deleted ON se_registrations (event_id, deleted_at)',
    'DO 0'
);
PREPARE se_del_mig FROM @sql;
EXECUTE se_del_mig;
DEALLOCATE PREPARE se_del_mig;
