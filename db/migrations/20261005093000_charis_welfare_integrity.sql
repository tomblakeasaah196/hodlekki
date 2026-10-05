-- 20261005093000_charis_welfare_integrity.sql
-- Complete the persisted welfare workflow used by Charis: confidential notes,
-- an actual resolution timestamp for archive/report periods, and indexes for
-- the active-case and report queries. Every alteration is guarded so this is
-- safe on older installations where some Charis tables were created manually.

CREATE TABLE IF NOT EXISTS charis_welfare_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    note_text TEXT NOT NULL,
    visible_to LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_charis_welfare_notes_target_created (target_user_id, created_at),
    KEY idx_charis_welfare_notes_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_welfare_assignments') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'charis_welfare_assignments'
            AND column_name = 'resolved_at') = 0,
    'ALTER TABLE charis_welfare_assignments ADD COLUMN resolved_at DATETIME NULL AFTER status',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_welfare_assignments') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'charis_welfare_assignments'
            AND index_name = 'idx_charis_welfare_target_status') = 0,
    'CREATE INDEX idx_charis_welfare_target_status ON charis_welfare_assignments (target_user_id, followup_id, status)',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'charis_welfare_assignments') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'charis_welfare_assignments'
            AND index_name = 'idx_charis_welfare_status_created') = 0,
    'CREATE INDEX idx_charis_welfare_status_created ON charis_welfare_assignments (status, created_at)',
    'DO 0'
);
PREPARE charis_schema FROM @sql;
EXECUTE charis_schema;
DEALLOCATE PREPARE charis_schema;
