-- 20261021150000_sms_worker_heartbeat_seen.sql
-- sms_worker_heartbeat.last_cron_seen_at: stamped by cron/sms_queue_worker.php
-- at the very start of every cron run, before the named lock and before any
-- work. last_cron_at (stamped only when a run completes) could not tell a cron
-- job that never fires from one that fires every minute and dies — both showed
-- "Background sender stopped" in SMS Studio. Guarded like the other SMS column
-- changes so re-running is a no-op.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = @db AND table_name = 'sms_worker_heartbeat') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = @db AND table_name = 'sms_worker_heartbeat' AND column_name = 'last_cron_seen_at') = 0,
    'ALTER TABLE sms_worker_heartbeat ADD COLUMN last_cron_seen_at DATETIME NULL DEFAULT NULL',
    'DO 0'
);
PREPARE sms_mig FROM @sql;
EXECUTE sms_mig;
DEALLOCATE PREPARE sms_mig;
