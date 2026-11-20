-- 20261221090000_events_category_free_text.sql
-- "Workers' Meeting" is a new event category (Workers_Meeting). The category
-- list lives in code, so a plain text column takes it with no change. This
-- file is only a safety net: IF events.event_category was ever created as an
-- ENUM (which would refuse the new value), it becomes VARCHAR(64) NOT NULL;
-- every existing value is kept as text. Otherwise it does nothing, and a
-- missing table is a no-op rather than a failed deploy.
--
-- Guarded by an information_schema check and run through PREPARE (plain
-- ;-terminated SQL, no DELIMITER block). Re-running the file is a no-op.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'events'
        AND column_name = 'event_category' AND data_type = 'enum') = 1,
    'ALTER TABLE events MODIFY event_category VARCHAR(64) NOT NULL',
    'DO 0'
);
PREPARE events_cat_text FROM @sql;
EXECUTE events_cat_text;
DEALLOCATE PREPARE events_cat_text;
