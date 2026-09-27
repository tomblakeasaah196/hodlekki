-- 20261004090200_sms_columns_and_indexes.sql
-- Column and index changes the SMS Studio hardening relies on, applied to the
-- hand-made production tables (see 20261004090000_sms_baseline_tables.sql).
--
-- 1. Status columns become VARCHAR(20). If any of them is an ENUM (or a short
--    VARCHAR), the new values the worker now writes — 'queued' on sms_log
--    before the BulkSMS call, 'cancelled' on sms_queue / sms_campaigns,
--    'sending' on sms_campaigns — would be rejected in strict mode and crash a
--    send mid-flight. Only widened when narrower; existing values are kept.
-- 2. New columns: sms_log.dlr_checked_at (when the worker last asked BulkSMS
--    for a delivery report), sms_queue.log_id (links a queue job to the exact
--    sms_log row it produced, so a crashed run can be recovered without
--    sending anyone a message twice), sms_webhook_log.received_at (only when
--    the table has no timestamp column at all).
-- 3. Indexes for the lookups done on every send / callback: sms_log by
--    message_id (webhook), by recipient_phone + created_at (spam guard, person
--    history), by campaign_id, by status + created_at (delivery polling);
--    sms_queue by status and campaign.
--
-- MySQL 8 has no ADD COLUMN / CREATE INDEX IF NOT EXISTS, so every change is
-- guarded by an information_schema check and run through PREPARE (plain
-- ;-terminated SQL, no DELIMITER). Re-running is a no-op. An index is skipped
-- when any index already starts with that column, or when the column type is
-- not safely indexable (e.g. a TEXT message_id), so nothing here can fail the
-- deploy on an unexpected hand-made column type.

SET @db := DATABASE();

-- ---------------------------------------------------------------- 1. widen status columns

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'status'
        AND (data_type = 'enum' OR (data_type IN ('varchar','char') AND character_maximum_length < 20))) = 1,
    'ALTER TABLE sms_log MODIFY COLUMN status VARCHAR(20) NULL DEFAULT ''queued''',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_queue' AND column_name = 'status'
        AND (data_type = 'enum' OR (data_type IN ('varchar','char') AND character_maximum_length < 20))) = 1,
    'ALTER TABLE sms_queue MODIFY COLUMN status VARCHAR(20) NULL DEFAULT ''queued''',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_queue' AND column_name = 'sent_status'
        AND (data_type = 'enum' OR (data_type IN ('varchar','char') AND character_maximum_length < 20))) = 1,
    'ALTER TABLE sms_queue MODIFY COLUMN sent_status VARCHAR(20) NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_campaigns' AND column_name = 'status'
        AND (data_type = 'enum' OR (data_type IN ('varchar','char') AND character_maximum_length < 20))) = 1,
    'ALTER TABLE sms_campaigns MODIFY COLUMN status VARCHAR(20) NULL DEFAULT ''queued''',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

-- ---------------------------------------------------------------- 2. new columns

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'sms_log') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'dlr_checked_at') = 0,
    'ALTER TABLE sms_log ADD COLUMN dlr_checked_at DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'sms_queue') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_queue' AND column_name = 'log_id') = 0,
    'ALTER TABLE sms_queue ADD COLUMN log_id INT NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'sms_webhook_log') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_webhook_log'
            AND column_name IN ('received_at','created_at')) = 0,
    'ALTER TABLE sms_webhook_log ADD COLUMN received_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

-- ---------------------------------------------------------------- 3. indexes

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'message_id'
        AND data_type IN ('varchar','char') AND character_maximum_length <= 191) = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_log'
            AND column_name = 'message_id' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_log_message_id ON sms_log (message_id)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'recipient_phone'
        AND data_type IN ('varchar','char') AND character_maximum_length <= 191) = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'created_at') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_log'
            AND column_name = 'recipient_phone' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_log_phone_created ON sms_log (recipient_phone, created_at)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'campaign_id'
        AND data_type IN ('int','bigint','mediumint','smallint')) = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_log'
            AND column_name = 'campaign_id' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_log_campaign ON sms_log (campaign_id)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'status'
        AND data_type IN ('varchar','char') AND character_maximum_length <= 191) = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_log' AND column_name = 'created_at') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_log'
            AND column_name = 'status' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_log_status_created ON sms_log (status, created_at)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_queue' AND column_name = 'status'
        AND data_type IN ('varchar','char') AND character_maximum_length <= 191) = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_queue'
            AND column_name = 'status' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_queue_status ON sms_queue (status, id)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_queue' AND column_name = 'campaign_id'
        AND data_type IN ('int','bigint','mediumint','smallint')) = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_queue'
            AND column_name = 'campaign_id' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_queue_campaign ON sms_queue (campaign_id)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_webhook_log' AND column_name = 'message_id'
        AND data_type IN ('varchar','char') AND character_maximum_length <= 191) = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_webhook_log'
            AND column_name = 'message_id' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_webhook_log_message ON sms_webhook_log (message_id)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'sms_campaigns' AND column_name = 'event_id'
        AND data_type IN ('int','bigint','mediumint','smallint')) = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = @db AND table_name = 'sms_campaigns'
            AND column_name = 'event_id' AND seq_in_index = 1) = 0,
    'CREATE INDEX idx_sms_campaigns_event ON sms_campaigns (event_id)',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;
