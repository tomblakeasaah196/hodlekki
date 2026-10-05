-- 20261005120000_assimilation_open_watchlists.sql
-- "Open" watchlists: a manager can mark a watchlist as open to every
-- volunteer. Matching people are pushed straight into the unclaimed pool
-- (assimilation_cases rows with assigned_to NULL) the moment the list is
-- saved, and the nightly cron keeps pushing genuinely-new drift-ins. The
-- first volunteer who claims a person takes them off the shared list.
--
-- ALTER TABLE ... ADD COLUMN has no IF NOT EXISTS in MySQL 8, so the add is
-- guarded by an information_schema check and run through PREPARE — plain
-- ;-terminated SQL, no DELIMITER block. Re-running the file is a no-op, and
-- a missing table is a no-op rather than a failed deploy.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'assimilation_watchlists') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'assimilation_watchlists'
            AND column_name = 'is_open') = 0,
    "ALTER TABLE assimilation_watchlists ADD COLUMN is_open TINYINT(1) NOT NULL DEFAULT 0 AFTER notify",
    'DO 0'
);
PREPARE assim_wl_open FROM @sql;
EXECUTE assim_wl_open;
DEALLOCATE PREPARE assim_wl_open;
