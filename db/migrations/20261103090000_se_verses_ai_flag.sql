-- 20261103090000_se_verses_ai_flag.sql
-- Verse provenance (guide §15.7): a verse accepted from an AI suggestion is
-- marked, so the Studio list and the audit trail agree about where the verse
-- came from. Accepting in the picker approves at the same moment — the
-- column records who proposed the verse, not who approved it.
--
-- ADD COLUMN has no IF NOT EXISTS in MySQL 8, so the guard follows the
-- repo's idempotent-DDL pattern: an information_schema check run through
-- PREPARE — plain ;-terminated SQL, no DELIMITER block. Re-running the file
-- is a no-op (the test harness applies module migrations more than once,
-- and a warm production database tolerates a cautious re-run too).

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'se_event_verses') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'se_event_verses'
            AND column_name = 'suggested_by_ai') = 0,
    'ALTER TABLE se_event_verses ADD COLUMN suggested_by_ai TINYINT(1) NOT NULL DEFAULT 0 AFTER second_approved_by',
    'DO 0'
);
PREPARE se_verses_ai_flag FROM @sql;
EXECUTE se_verses_ai_flag;
DEALLOCATE PREPARE se_verses_ai_flag;
