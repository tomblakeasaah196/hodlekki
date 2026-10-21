-- 20261021150100_sms_queue_attempts.sql
-- sms_queue.attempts: how many times the worker has handed this row to BulkSMS.
-- A temporary refusal (HTTP 429, gateway busy, a 5xx with a readable error —
-- nothing billed) used to fail the recipient for good; the worker now puts the
-- row back in the queue for the next minute, at most twice. Guarded like the
-- other SMS column changes so re-running is a no-op.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'sms_queue') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_queue' AND column_name = 'attempts') = 0,
    'ALTER TABLE sms_queue ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;
