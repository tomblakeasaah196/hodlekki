-- /db/migrations/20261219090000_trainings_live_access.sql
--
-- Trainings live classes: who can join the Meet (owner, 19 Nov 2026; docs/INTEGRATIONS.md, "Who can join").
-- A live item's details.access is open | learners | guests. Here the meeting keeps what was last sent to
-- Google, so a change is noticed and sent again:
--   access_sent  the access last set on the Meet space (open | learners | guests)
--   space_name   the Meet space's resource name (spaces/…), found once from the meeting code
--   guests_sent  JSON list of the learners' emails sent as Calendar guests (guests only)
-- Re-runnable (information_schema check), portable to MySQL 8 and MariaDB 10.11.

SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_meetings') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_meetings' AND column_name = 'access_sent') = 0,
    'ALTER TABLE training_meetings ADD COLUMN access_sent VARCHAR(10) NULL DEFAULT NULL', 'DO 0');
PREPARE live_s FROM @sql; EXECUTE live_s; DEALLOCATE PREPARE live_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_meetings') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_meetings' AND column_name = 'space_name') = 0,
    'ALTER TABLE training_meetings ADD COLUMN space_name VARCHAR(80) NULL DEFAULT NULL', 'DO 0');
PREPARE live_s FROM @sql; EXECUTE live_s; DEALLOCATE PREPARE live_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'training_meetings') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'training_meetings' AND column_name = 'guests_sent') = 0,
    'ALTER TABLE training_meetings ADD COLUMN guests_sent LONGTEXT NULL', 'DO 0');
PREPARE live_s FROM @sql; EXECUTE live_s; DEALLOCATE PREPARE live_s;
